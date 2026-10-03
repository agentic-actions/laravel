import { newKey, type ActionDefinition } from './action.js';
import { ActionFailedError, ActionRefusedError, ActionValidationError } from './errors.js';
import { notifyTouched } from './touches.js';

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
export async function callAction<I, O>(definition: ActionDefinition<I, O>, input: I, options: CallOptions = {}): Promise<O> {
    const method = definition.method.toUpperCase();

    if (method === 'GET' || method === 'HEAD') {
        throw new Error(`callAction() cannot call [${definition.name}] over ${method}: it sends the input as the request body, which a ${method} request cannot carry.`);
    }

    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'Idempotency-Key': options.idempotencyKey ?? newKey(),
    };

    const token = xsrfToken();

    if (token !== '' && (sameOrigin(definition.url) || options.credentials === 'include')) {
        headers['X-XSRF-TOKEN'] = token;
    }

    let body: BodyInit;

    if (hasBlob(input)) {
        body = toFormData(input);
    } else {
        body = JSON.stringify(input);
        headers['Content-Type'] = 'application/json';
    }

    const response = await (options.fetch ?? fetch)(definition.url, {
        method,
        headers: { ...headers, ...options.headers },
        body,
        credentials: options.credentials ?? 'same-origin',
        signal: options.signal,
    });

    const text = await response.text();
    const parsed = parse(text);
    const status = response.status;

    if (parsed === undefined || status >= 500) {
        throw new ActionFailedError(messageOf(parsed), status);
    }

    if (status >= 200 && status < 300) {
        notifyTouched(definition.touches, definition);

        return parsed as O;
    }

    const errors = isRecord(parsed) ? parsed['errors'] : undefined;

    if (status === 422 && isRecord(errors)) {
        throw new ActionValidationError(messageOf(parsed), firstMessages(errors));
    }

    if (status >= 400) {
        const code = isRecord(parsed) && typeof parsed['code'] === 'string' ? parsed['code'] : undefined;
        const details = isRecord(parsed) && isRecord(parsed['details']) ? parsed['details'] : undefined;

        throw new ActionRefusedError(messageOf(parsed), status, code, details);
    }

    throw new ActionFailedError(messageOf(parsed), status);
}

/** The XSRF-TOKEN cookie's value, or '' where there is no document. */
export function xsrfToken(): string {
    if (typeof document === 'undefined' || typeof document.cookie !== 'string') {
        return '';
    }

    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    if (match === null) {
        return '';
    }

    try {
        return decodeURIComponent(match[1] ?? '');
    } catch {
        return '';
    }
}

/** Whether a URL, resolved against the page, has the page's origin. False where there is no page. */
function sameOrigin(url: string): boolean {
    if (typeof location === 'undefined') {
        return false;
    }

    try {
        return new URL(url, location.href).origin === location.origin;
    } catch {
        return false;
    }
}

/** The decoded body: {} when empty, undefined when it is not JSON. */
function parse(text: string): unknown {
    if (text.trim() === '') {
        return {};
    }

    try {
        return JSON.parse(text) as unknown;
    } catch {
        return undefined;
    }
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function messageOf(body: unknown): string {
    return isRecord(body) && typeof body['message'] === 'string' ? body['message'] : '';
}

/** Laravel sends a list of messages per key; keep the first. */
function firstMessages(errors: Record<string, unknown>): Record<string, string> {
    const first: Record<string, string> = {};

    for (const [key, messages] of Object.entries(errors)) {
        const message: unknown = Array.isArray(messages) ? messages[0] : messages;

        if (typeof message === 'string') {
            first[key] = message;
        }
    }

    return first;
}

function hasBlob(value: unknown): boolean {
    if (typeof Blob !== 'undefined' && value instanceof Blob) {
        return true;
    }

    if (Array.isArray(value)) {
        return value.some(hasBlob);
    }

    return isRecord(value) && !(value instanceof Date) && Object.values(value).some(hasBlob);
}

/** Bracket notation, as PHP reads it: tags[0], author[name]; booleans as '1'/'0'; null as ''. */
function toFormData(input: unknown): FormData {
    const form = new FormData();

    const append = (key: string, value: unknown): void => {
        if (value === undefined) {
            return;
        }

        if (value === null) {
            form.append(key, '');
        } else if (typeof value === 'boolean') {
            form.append(key, value ? '1' : '0');
        } else if (value instanceof Blob) {
            form.append(key, value);
        } else if (value instanceof Date) {
            form.append(key, value.toISOString());
        } else if (Array.isArray(value)) {
            value.forEach((item, index) => append(`${key}[${index}]`, item));
        } else if (isRecord(value)) {
            Object.entries(value).forEach(([name, item]) => append(`${key}[${name}]`, item));
        } else {
            form.append(key, String(value));
        }
    };

    if (isRecord(input)) {
        Object.entries(input).forEach(([key, value]) => append(key, value));
    }

    return form;
}
