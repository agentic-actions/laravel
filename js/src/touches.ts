import type { ActionDefinition } from './action.js';

/** The definition is null for touches that carry none: a copilot row's, or a host's own touch(). */
export type TouchedHandler = (touches: readonly string[], definition: ActionDefinition | null) => void;

/** One entry per registration, so a handler registered twice is called twice and unsubscribes once each time. */
const handlers = new Set<{ handler: TouchedHandler }>();

/** Register a handler for the keys a successful call made stale. Returns the unsubscribe function. */
export function onTouched(handler: TouchedHandler): () => void {
    const registration = { handler };

    handlers.add(registration);

    return () => {
        handlers.delete(registration);
    };
}

/** Pass touches to every registered handler. callAction, /react's useAction and createActionSync's default apply call it. */
export function notifyTouched(touches: readonly string[], definition: ActionDefinition | null = null): void {
    if (touches.length === 0) {
        return;
    }

    for (const { handler } of [...handlers]) {
        try {
            handler(touches, definition);
        } catch (error) {
            queueMicrotask(() => {
                throw error;
            });
        }
    }
}
