import { xsrfToken } from './call.js';
import { sameOriginUrl, type ActionPart } from './parts.js';
import { notifyTouched } from './touches.js';

/** What one refresh asks for. */
export type SyncRequest = { touches: string[]; follow: string | null; how: 'reload' | 'remount' };

export type ActionFeedOptions = {
    /** The group's feed route, POST {prefix}/actions/_changes. */
    url: string;
    /** Milliseconds between polls while the page is visible, at least 1 000. Default 15 000. */
    interval?: number;
    /** A fetch implementation, for tests and non-browser runtimes. */
    fetch?: typeof fetch;
};

export type ActionSyncOptions = {
    /** Refresh what the request names. Default: touches go to the onTouched() handlers; a followed link loads with location.assign(). */
    apply?: (request: SyncRequest) => void | Promise<unknown>;
    /** 'live' (default): 150 ms after the last done row. 'turn': only at flush(). */
    when?: 'live' | 'turn';
    /** Passed to apply(). 'remount' is for forms seeded from the page's data. Default 'reload'. */
    how?: 'reload' | 'remount';
    /**
     * The host's own unsaved-work flag, read when a refresh is due. The editors store is read too. The sync cannot see
     * this flag change: when it turns false while a refresh is held, call flush().
     */
    blocked?: () => boolean;
    /**
     * true (default): a held refresh runs by itself 150 ms after the editors store turns clean, unless blocked() or an
     * editor holds it again by then. false: it stays held until apply(), or a done row or flush() that finds nothing
     * holding it.
     */
    resumeWhenClean?: boolean;
    /** A refresh is held for unsaved work (true), or has run or been dropped (false). */
    onWaiting?: (waiting: boolean) => void;
    /**
     * Poll the change feed so writes made elsewhere (MCP, the queue, another person or tab) reach this page, through the
     * same holds as a done row. Off unless given. Polls only while the page is visible and in use.
     */
    feed?: ActionFeedOptions;
};

/** Every member is a function property, so each can be destructured or passed on unbound. */
export type ActionSync = {
    /** Feed a data-action part. Only a done row counts: its touches, and its link when it may be followed. */
    onPart: (part: ActionPart) => void;
    /** Add keys by hand, for a write the host made itself. */
    touch: (keys: readonly string[]) => void;
    /** End of a turn: runs a 'turn' refresh, and any pending 'live' one, unless something holds it. Call it after an error too. */
    flush: () => void;
    /** Run what is pending now, even while held: the host's Refresh button. */
    apply: () => void;
    /** Stop the timer and the feed, and stop watching the editors store. */
    dispose: () => void;
};

/** The writes of one step land within this window, so they become one refresh. */
const WINDOW_MS = 150;

const FEED_INTERVAL_MS = 15_000;

/** Pause after this long with no pointer or keyboard input, so an idle tab does not keep its session alive. */
const FEED_IDLE_MS = 600_000;

/** At most one poll a second, whatever interval the host passes or however often the page turns visible. */
const FEED_MIN_MS = 1000;

/** The longest delay setTimeout keeps: a longer one, or Infinity, fires at once. */
const FEED_MAX_MS = 2_147_483_647;

const dirtyEditors = new Set<symbol>();
const editorListeners = new Set<{ listener: () => void }>();

/**
 * Editors the copilot must not refresh under. Module state, shared by every sync in the page. Every member is a
 * function property, so each can be destructured or passed on unbound.
 */
export const editors: {
    set: (key: symbol, dirty: boolean) => void;
    dirty: () => boolean;
    subscribe: (listener: () => void) => () => void;
} = {
    set: (key: symbol, dirty: boolean): void => {
        if (dirtyEditors.has(key) === dirty) {
            return;
        }

        if (dirty) {
            dirtyEditors.add(key);
        } else {
            dirtyEditors.delete(key);
        }

        for (const { listener } of [...editorListeners]) {
            listener();
        }
    },
    dirty: (): boolean => dirtyEditors.size > 0,
    subscribe: (listener: () => void): (() => void) => {
        const registration = { listener };

        editorListeners.add(registration);

        return () => {
            editorListeners.delete(registration);
        };
    },
};

/** Coalesce done rows into one refresh at a time, and hold it while the person has unsaved work. */
export function createActionSync(options: ActionSyncOptions = {}): ActionSync {
    const apply = options.apply ?? defaultApply;
    const pending = new Set<string>();
    let follow: string | null = null;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let inFlight = false;
    let waiting = false;
    let unwatch: (() => void) | null = null;

    // While a refresh is held, the editors store turning clean starts the window again; due() then reads every hold.
    const watch = (held: boolean): void => {
        if (!held) {
            unwatch?.();
            unwatch = null;
        } else if (unwatch === null && options.resumeWhenClean !== false) {
            unwatch = editors.subscribe(() => {
                if (!editors.dirty()) {
                    schedule();
                }
            });
        }
    };

    const setWaiting = (value: boolean): void => {
        watch(value);

        if (waiting !== value) {
            waiting = value;
            options.onWaiting?.(value);
        }
    };

    const schedule = (force = false): void => {
        clearTimeout(timer);
        timer = setTimeout(() => due(force), WINDOW_MS);
    };

    // The hold is read when the refresh is due, so an editor that turns dirty inside the window still holds it.
    const due = (force: boolean): void => {
        timer = undefined;

        if (pending.size === 0 && follow === null) {
            setWaiting(false);
        } else if (!force && ((options.blocked?.() ?? false) || editors.dirty())) {
            setWaiting(true);
        } else if (inFlight) {
            schedule(force);
        } else {
            const request: SyncRequest = { touches: [...pending], follow, how: options.how ?? 'reload' };

            pending.clear();
            follow = null;
            setWaiting(false);
            inFlight = true;

            new Promise<unknown>((resolve) => resolve(apply(request)))
                .catch(rethrow)
                .finally(() => {
                    inFlight = false;
                });
        }
    };

    // Feed touches schedule a refresh even with when: 'turn', since no turn will flush them.
    const add = (keys: readonly string[], target: string | null, always = false): void => {
        keys.forEach((key) => pending.add(key));
        follow = target ?? follow;

        if ((always || options.when !== 'turn') && (keys.length > 0 || target !== null)) {
            schedule();
        }
    };

    const stopFeed = options.feed !== undefined && typeof document !== 'undefined' ? pollFeed(options.feed, (keys) => add(keys, null, true)) : null;

    return {
        onPart: (part: ActionPart): void => {
            if (part.data.status === 'done') {
                const link = part.data.link?.follow === true ? sameOriginUrl(part.data.link.url) : null;

                add(part.data.touches ?? [], link?.href ?? null);
            }
        },
        touch: (keys: readonly string[]): void => add(keys, null),
        flush: (): void => schedule(),
        apply: (): void => {
            clearTimeout(timer);
            due(true);
        },
        dispose: (): void => {
            clearTimeout(timer);
            watch(false);
            stopFeed?.();
        },
    };
}

/** Poll while the page is visible and in use; stop for good on a 4xx other than 429. Returns the stop function. */
function pollFeed(feed: ActionFeedOptions, onTouches: (keys: string[]) => void): () => void {
    let since: number | null = null;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let busy = false;
    let stopped = false;
    let lastInput = Date.now();
    let lastPoll = -Infinity;
    const every = Number.isFinite(feed.interval) ? Math.min(Math.max(feed.interval as number, FEED_MIN_MS), FEED_MAX_MS) : FEED_INTERVAL_MS;

    const poll = async (): Promise<void> => {
        clearTimeout(timer);

        // Hidden or idle: set no timer; turning visible or the next input polls at once.
        if (stopped || busy || document.hidden || Date.now() - lastInput > FEED_IDLE_MS) {
            return;
        }

        busy = true;
        lastPoll = Date.now();

        try {
            const token = xsrfToken();
            const response = await (feed.fetch ?? fetch)(feed.url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    ...(token !== '' && sameOriginUrl(feed.url) !== null ? { 'X-XSRF-TOKEN': token } : {}),
                },
                body: JSON.stringify({ since }),
            });

            if (response.status >= 400 && response.status < 500 && response.status !== 429) {
                stop();
            } else if (response.ok) {
                const body = (await response.json()) as { now?: unknown; touches?: unknown };

                // dispose() may have run while the request was in flight: apply nothing to a disposed sync.
                if (!stopped && typeof body.now === 'number') {
                    since = body.now;

                    const keys = Array.isArray(body.touches) ? body.touches.filter((key): key is string => typeof key === 'string') : [];

                    if (keys.length > 0) {
                        onTouches(keys);
                    }
                }
            }
        } catch {
            // A network error or a body that is not JSON: try again at the next interval.
        } finally {
            busy = false;
        }

        if (!stopped) {
            timer = setTimeout(() => void poll(), every);
        }
    };

    // A poll the page asks for comes at once, or when a second has passed since the last one; one in flight sets the
    // next timer itself.
    const soon = (): void => {
        if (busy) {
            return;
        }

        const wait = lastPoll + FEED_MIN_MS - Date.now();

        if (wait > 0) {
            clearTimeout(timer);
            timer = setTimeout(() => void poll(), wait);
        } else {
            void poll();
        }
    };

    // Coming back to the tab counts as input.
    const onVisibility = (): void => {
        if (!document.hidden) {
            lastInput = Date.now();
            soon();
        }
    };

    const onInput = (): void => {
        const wasIdle = Date.now() - lastInput > FEED_IDLE_MS;

        lastInput = Date.now();

        if (wasIdle) {
            soon();
        }
    };

    const stop = (): void => {
        stopped = true;
        clearTimeout(timer);
        document.removeEventListener('visibilitychange', onVisibility);
        document.removeEventListener('pointerdown', onInput);
        document.removeEventListener('keydown', onInput);
    };

    document.addEventListener('visibilitychange', onVisibility);
    document.addEventListener('pointerdown', onInput, { passive: true });
    document.addEventListener('keydown', onInput, { passive: true });
    void poll();

    return stop;
}

/** Without an apply(), a followed link loads the page, and touches go to the onTouched() handlers. */
function defaultApply(request: SyncRequest): void {
    if (request.follow !== null) {
        location.assign(request.follow);
    } else {
        notifyTouched(request.touches, null);
    }
}

/** An apply() that fails never blocks the next one; its error surfaces on its own, as notifyTouched()'s do. */
function rethrow(error: unknown): void {
    queueMicrotask(() => {
        throw error;
    });
}
