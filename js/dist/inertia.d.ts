import { type SyncRequest } from '@agentic-actions/client';
/**
 * Reload what a success made stale: '*' flushes every prefetch and reloads all props; otherwise flush the tags and
 * reload only those props. onFinish runs when Inertia finishes the visit, or at once when there is none.
 */
export declare function reloadTouched(touches: readonly string[], options?: {
    onFinish?: () => void;
}): void;
/**
 * The default apply() for createActionSync() on Inertia. Resolves when the visit finishes. A followed link visits the
 * new page, and a remount revisits this one, keeping its state when an editor turned dirty while the visit was out.
 */
export declare function inertiaApply(request: SyncRequest): Promise<void>;
/** Register reloadTouched() as a touches handler once. Returns the uninstall function. */
export declare function installInertiaReload(): () => void;
