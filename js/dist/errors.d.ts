/** Any answer from the server that is not a success. */
export declare class ActionError extends Error {
    readonly status: number;
    constructor(message: string, status: number);
}
/** 422 with field errors: the first message per key. */
export declare class ActionValidationError extends ActionError {
    readonly errors: Record<string, string>;
    constructor(message: string, errors: Record<string, string>);
}
/** Any other 4xx: the server's fixed sentence or the action's refusal. */
export declare class ActionRefusedError extends ActionError {
    readonly code?: string;
    readonly details?: Record<string, unknown>;
    constructor(message: string, status: number, code?: string, details?: Record<string, unknown>);
}
/** 5xx, or a body that is not JSON. */
export declare class ActionFailedError extends ActionError {
    constructor(message: string, status: number);
}
