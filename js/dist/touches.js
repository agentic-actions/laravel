/** One entry per registration, so a handler registered twice is called twice and unsubscribes once each time. */
const handlers = new Set();
/** Register a handler for the keys a successful call made stale. Returns the unsubscribe function. */
export function onTouched(handler) {
    const registration = { handler };
    handlers.add(registration);
    return () => {
        handlers.delete(registration);
    };
}
/** Pass touches to every registered handler. callAction, /react's useAction and createActionSync's default apply call it. */
export function notifyTouched(touches, definition = null) {
    if (touches.length === 0) {
        return;
    }
    for (const { handler } of [...handlers]) {
        try {
            handler(touches, definition);
        }
        catch (error) {
            queueMicrotask(() => {
                throw error;
            });
        }
    }
}
