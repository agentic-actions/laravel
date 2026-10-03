/** Any answer from the server that is not a success. */
export class ActionError extends Error {
    status;
    constructor(message, status) {
        super(message);
        this.name = 'ActionError';
        this.status = status;
    }
}
/** 422 with field errors: the first message per key. */
export class ActionValidationError extends ActionError {
    errors;
    constructor(message, errors) {
        super(message, 422);
        this.name = 'ActionValidationError';
        this.errors = errors;
    }
}
/** Any other 4xx: the server's fixed sentence or the action's refusal. */
export class ActionRefusedError extends ActionError {
    code;
    details;
    constructor(message, status, code, details) {
        super(message, status);
        this.name = 'ActionRefusedError';
        this.code = code;
        this.details = details;
    }
}
/** 5xx, or a body that is not JSON. */
export class ActionFailedError extends ActionError {
    constructor(message, status) {
        super(message, status);
        this.name = 'ActionFailedError';
    }
}
