import { type ActionPart } from './parts.js';
/** What one refresh asks for. */
export type SyncRequest = {
    touches: string[];
    follow: string | null;
    how: 'reload' | 'remount';
};
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
/**
 * Editors the copilot must not refresh under. Module state, shared by every sync in the page. Every member is a
 * function property, so each can be destructured or passed on unbound.
 */
export declare const editors: {
    set: (key: symbol, dirty: boolean) => void;
    dirty: () => boolean;
    subscribe: (listener: () => void) => () => void;
};
/** Coalesce done rows into one refresh at a time, and hold it while the person has unsaved work. */
export declare function createActionSync(options?: ActionSyncOptions): ActionSync;
