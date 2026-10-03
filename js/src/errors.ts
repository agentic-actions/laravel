/** Any answer from the server that is not a success. */
export class ActionError extends Error {
    readonly status: number;

    constructor(message: string, status: number) {
        super(message);
        this.name = 'ActionError';
        this.status = status;
    }
}

/** 422 with field errors: the first message per key. */
export class ActionValidationError extends ActionError {
    readonly errors: Record<string, string>;

    constructor(message: string, errors: Record<string, string>) {
        super(message, 422);
        this.name = 'ActionValidationError';
        this.errors = errors;
    }
}

/** Any other 4xx: the server's fixed sentence or the action's refusal. */
export class ActionRefusedError extends ActionError {
    readonly code?: string;
    readonly details?: Record<string, unknown>;

    constructor(message: string, status: number, code?: string, details?: Record<string, unknown>) {
        super(message, status);
        this.name = 'ActionRefusedError';
        this.code = code;
        this.details = details;
    }
}

/** 5xx, or a body that is not JSON. */
export class ActionFailedError extends ActionError {
    constructor(message: string, status: number) {
        super(message, status);
        this.name = 'ActionFailedError';
    }
}
