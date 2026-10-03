import { router } from '@inertiajs/core';
import { editors, onTouched, type SyncRequest } from '@agentic-actions/client';

/** The uninstall function of the one registered handler, or null while none is registered. */
let installed: (() => void) | null = null;

/**
 * Reload what a success made stale: '*' flushes every prefetch and reloads all props; otherwise flush the tags and
 * reload only those props. onFinish runs when Inertia finishes the visit, or at once when there is none.
 */
export function reloadTouched(touches: readonly string[], options: { onFinish?: () => void } = {}): void {
    if (touches.length === 0) {
        options.onFinish?.();

        return;
    }

    flushCache(touches);
    router.reload(touches.includes('*') ? { ...options } : { ...options, only: [...touches] });
}

/**
 * The default apply() for createActionSync() on Inertia. Resolves when the visit finishes. A followed link visits the
 * new page, and a remount revisits this one, keeping its state when an editor turned dirty while the visit was out.
 */
export function inertiaApply(request: SyncRequest): Promise<void> {
    return new Promise((resolve) => {
        const onFinish = (): void => resolve();

        if (request.follow === null && request.how === 'reload') {
            reloadTouched(request.touches, { onFinish });

            return;
        }

        flushCache(request.touches);

        if (request.follow !== null) {
            router.visit(request.follow, { onFinish });
        } else {
            router.visit(location.href, { preserveState: () => editors.dirty(), preserveScroll: true, replace: true, onFinish });
        }
    });
}

/** Register reloadTouched() as a touches handler once. Returns the uninstall function. */
export function installInertiaReload(): () => void {
    installed ??= onTouched((touches) => reloadTouched(touches));

    return () => {
        installed?.();
        installed = null;
    };
}

/** Drop the prefetched pages the touches made stale: every one for '*', else those tagged with a touched key. */
function flushCache(touches: readonly string[]): void {
    if (touches.includes('*')) {
        router.flushAll();
    } else if (touches.length > 0) {
        router.flushByCacheTags([...touches]);
    }
}
