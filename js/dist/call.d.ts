import { type ActionDefinition } from './action.js';
export type CallOptions = {
    /** Reused by a caller that retries; a fresh newKey() otherwise. */
    idempotencyKey?: string;
    headers?: Record<string, string>;
    /** Default 'same-origin'. 'include' is the Sanctum SPA on another origin. */
    credentials?: RequestCredentials;
    signal?: AbortSignal;
    /** A fetch implementation, for tests and non-browser runtimes. */
    fetch?: typeof fetch;
};
/**
 * Call an action and resolve its output. Always sends Accept: application/json. Never retries on its own.
 *
 * @throws ActionValidationError | ActionRefusedError | ActionFailedError, or fetch's own network error
 */
export declare function callAction<I, O>(definition: ActionDefinition<I, O>, input: I, options?: CallOptions): Promise<O>;
/** The XSRF-TOKEN cookie's value, or '' where there is no document. */
export declare function xsrfToken(): string;
