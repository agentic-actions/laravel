import { xsrfToken } from './call.js';
import { sameOriginUrl } from './parts.js';
import { notifyTouched } from './touches.js';
/** The writes of one step land within this window, so they become one refresh. */
const WINDOW_MS = 150;
const FEED_INTERVAL_MS = 15_000;
/** Pause after this long with no pointer or keyboard input, so an idle tab does not keep its session alive. */
const FEED_IDLE_MS = 600_000;
/** At most one poll a second, whatever interval the host passes or however often the page turns visible. */
const FEED_MIN_MS = 1000;
/** The longest delay setTimeout keeps: a longer one, or Infinity, fires at once. */
const FEED_MAX_MS = 2_147_483_647;
const dirtyEditors = new Set();
const editorListeners = new Set();
/**
 * Editors the copilot must not refresh under. Module state, shared by every sync in the page. Every member is a
 * function property, so each can be destructured or passed on unbound.
 */
export const editors = {
    set: (key, dirty) => {
        if (dirtyEditors.has(key) === dirty) {
            return;
        }
        if (dirty) {
            dirtyEditors.add(key);
        }
        else {
            dirtyEditors.delete(key);
        }
        for (const { listener } of [...editorListeners]) {
            listener();
        }
    },
    dirty: () => dirtyEditors.size > 0,
    subscribe: (listener) => {
        const registration = { listener };
        editorListeners.add(registration);
        return () => {
            editorListeners.delete(registration);
        };
    },
};
/** Coalesce done rows into one refresh at a time, and hold it while the person has unsaved work. */
export function createActionSync(options = {}) {
    const apply = options.apply ?? defaultApply;
    const pending = new Set();
    let follow = null;
    let timer;
    let inFlight = false;
    let waiting = false;
    let unwatch = null;
    // While a refresh is held, the editors store turning clean starts the window again; due() then reads every hold.
    const watch = (held) => {
        if (!held) {
            unwatch?.();
            unwatch = null;
        }
        else if (unwatch === null && options.resumeWhenClean !== false) {
            unwatch = editors.subscribe(() => {
                if (!editors.dirty()) {
                    schedule();
                }
            });
        }
    };
    const setWaiting = (value) => {
        watch(value);
        if (waiting !== value) {
            waiting = value;
            options.onWaiting?.(value);
        }
    };
    const schedule = (force = false) => {
        clearTimeout(timer);
        timer = setTimeout(() => due(force), WINDOW_MS);
    };
    // The hold is read when the refresh is due, so an editor that turns dirty inside the window still holds it.
    const due = (force) => {
        timer = undefined;
        if (pending.size === 0 && follow === null) {
            setWaiting(false);
        }
        else if (!force && ((options.blocked?.() ?? false) || editors.dirty())) {
            setWaiting(true);
        }
        else if (inFlight) {
            schedule(force);
        }
        else {
            const request = { touches: [...pending], follow, how: options.how ?? 'reload' };
            pending.clear();
            follow = null;
            setWaiting(false);
            inFlight = true;
            new Promise((resolve) => resolve(apply(request)))
                .catch(rethrow)
                .finally(() => {
                inFlight = false;
            });
        }
    };
    // Feed touches schedule a refresh even with when: 'turn', since no turn will flush them.
    const add = (keys, target, always = false) => {
        keys.forEach((key) => pending.add(key));
        follow = target ?? follow;
        if ((always || options.when !== 'turn') && (keys.length > 0 || target !== null)) {
            schedule();
        }
    };
    const stopFeed = options.feed !== undefined && typeof document !== 'undefined' ? pollFeed(options.feed, (keys) => add(keys, null, true)) : null;
    return {
        onPart: (part) => {
            if (part.data.status === 'done') {
                const link = part.data.link?.follow === true ? sameOriginUrl(part.data.link.url) : null;
                add(part.data.touches ?? [], link?.href ?? null);
            }
        },
        touch: (keys) => add(keys, null),
        flush: () => schedule(),
        apply: () => {
            clearTimeout(timer);
            due(true);
        },
        dispose: () => {
            clearTimeout(timer);
            watch(false);
            stopFeed?.();
        },
    };
}
/** Poll while the page is visible and in use; stop for good on a 4xx other than 429. Returns the stop function. */
function pollFeed(feed, onTouches) {
    let since = null;
    let timer;
    let busy = false;
    let stopped = false;
    let lastInput = Date.now();
    let lastPoll = -Infinity;
    const every = Number.isFinite(feed.interval) ? Math.min(Math.max(feed.interval, FEED_MIN_MS), FEED_MAX_MS) : FEED_INTERVAL_MS;
    const poll = async () => {
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
            }
            else if (response.ok) {
                const body = (await response.json());
                // dispose() may have run while the request was in flight: apply nothing to a disposed sync.
                if (!stopped && typeof body.now === 'number') {
                    since = body.now;
                    const keys = Array.isArray(body.touches) ? body.touches.filter((key) => typeof key === 'string') : [];
                    if (keys.length > 0) {
                        onTouches(keys);
                    }
                }
            }
        }
        catch {
            // A network error or a body that is not JSON: try again at the next interval.
        }
        finally {
            busy = false;
        }
        if (!stopped) {
            timer = setTimeout(() => void poll(), every);
        }
    };
    // A poll the page asks for comes at once, or when a second has passed since the last one; one in flight sets the
    // next timer itself.
    const soon = () => {
        if (busy) {
            return;
        }
        const wait = lastPoll + FEED_MIN_MS - Date.now();
        if (wait > 0) {
            clearTimeout(timer);
            timer = setTimeout(() => void poll(), wait);
        }
        else {
            void poll();
        }
    };
    // Coming back to the tab counts as input.
    const onVisibility = () => {
        if (!document.hidden) {
            lastInput = Date.now();
            soon();
        }
    };
    const onInput = () => {
        const wasIdle = Date.now() - lastInput > FEED_IDLE_MS;
        lastInput = Date.now();
        if (wasIdle) {
            soon();
        }
    };
    const stop = () => {
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
function defaultApply(request) {
    if (request.follow !== null) {
        location.assign(request.follow);
    }
    else {
        notifyTouched(request.touches, null);
    }
}
/** An apply() that fails never blocks the next one; its error surfaces on its own, as notifyTouched()'s do. */
function rethrow(error) {
    queueMicrotask(() => {
        throw error;
    });
}
