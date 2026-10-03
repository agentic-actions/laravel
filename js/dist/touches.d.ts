import type { ActionDefinition } from './action.js';
/** The definition is null for touches that carry none: a copilot row's, or a host's own touch(). */
export type TouchedHandler = (touches: readonly string[], definition: ActionDefinition | null) => void;
/** Register a handler for the keys a successful call made stale. Returns the unsubscribe function. */
export declare function onTouched(handler: TouchedHandler): () => void;
/** Pass touches to every registered handler. callAction, /react's useAction and createActionSync's default apply call it. */
export declare function notifyTouched(touches: readonly string[], definition?: ActionDefinition | null): void;
