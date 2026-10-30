import { newKey, type ActionDefinition } from './action.js';
import { ActionFailedError, ActionRefusedError, ActionValidationError } from './errors.js';
import { notifyTouched } from './touches.js';

export type CallOptions = {
    /** Reused by a caller that retries; a fresh newKey() otherwise. Never sent on a GET, which writes nothing. */
    idempotencyKey?: string;
    headers?: Record<string, string>;
    /** Default 'same-origin'. 'include' is the Sanctum SPA on another origin. */
    credentials?: RequestCredentials;
    signal?: AbortSignal;
    /** A fetch implementation, for tests and non-browser runtimes. */
    fetch?: typeof fetch;
};

/**
 * Call an action and resolve its output. Always sends Accept: application/json. Never retries on its own. A GET sends the
 * input as the query string, without an Idempotency-Key or X-XSRF-TOKEN; every other method sends it as the body.
 *
 * @throws ActionValidationError | ActionRefusedError | ActionFailedError, or fetch's own network error
 */
export async function callAction<I, O>(definition: ActionDefinition<I, O>, input: I, options: CallOptions = {}): Promise<O> {
    const method = definition.method.toUpperCase();

    if (method === 'HEAD') {
        throw new Error(`callAction() cannot call [${definition.name}] over HEAD: a HEAD answer has no body to resolve.`);
    }

    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    let url = definition.url;
    let body: BodyInit | undefined;

    if (method === 'GET') {
        // A Read: the input travels in the query string, in PHP's bracket form. Nothing is written, so no key or token.
        url = withQuery(url, toQuery(input, definition.name));
    } else {
        headers['Idempotency-Key'] = options.idempotencyKey ?? newKey();

        const token = xsrfToken();

        if (token !== '' && (sameOrigin(definition.url) || options.credentials === 'include')) {
            headers['X-XSRF-TOKEN'] = token;
        }

        if (hasBlob(input)) {
            body = toFormData(input);
        } else {
            body = JSON.stringify(input);
            headers['Content-Type'] = 'application/json';
        }
    }

    const response = await (options.fetch ?? fetch)(url, {
        method,
        headers: { ...headers, ...options.headers },
        ...(body === undefined ? {} : { body }),
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

/**
 * Each leaf of the input as a [key, value] pair in bracket notation, as PHP reads it: tags[0], author[name]; booleans as
 * '1'/'0'; null as ''; a Date as its ISO string. An undefined value, and so an empty list or object, sends nothing.
 */
function leaves(input: unknown, visit: (key: string, value: string | Blob) => void): void {
    const append = (key: string, value: unknown): void => {
        if (value === undefined) {
            return;
        }

        if (value === null) {
            visit(key, '');
        } else if (typeof value === 'boolean') {
            visit(key, value ? '1' : '0');
        } else if (value instanceof Blob) {
            visit(key, value);
        } else if (value instanceof Date) {
            visit(key, value.toISOString());
        } else if (Array.isArray(value)) {
            value.forEach((item, index) => append(`${key}[${index}]`, item));
        } else if (isRecord(value)) {
            Object.entries(value).forEach(([name, item]) => append(`${key}[${name}]`, item));
        } else {
            visit(key, String(value));
        }
    };

    if (isRecord(input)) {
        Object.entries(input).forEach(([key, value]) => append(key, value));
    }
}

function toFormData(input: unknown): FormData {
    const form = new FormData();

    leaves(input, (key, value) => form.append(key, value));

    return form;
}

/** A GET's query string. A file cannot travel in a URL. */
function toQuery(input: unknown, name: string): URLSearchParams {
    const query = new URLSearchParams();

    leaves(input, (key, value) => {
        if (typeof value !== 'string') {
            throw new Error(`callAction() cannot send a file to [${name}] over GET: a query string carries text only.`);
        }

        query.append(key, value);
    });

    return query;
}

/** The URL with the query appended, after any query it already has. */
function withQuery(url: string, query: URLSearchParams): string {
    const search = query.toString();

    if (search === '') {
        return url;
    }

    const [path, hash] = url.split('#', 2) as [string, string | undefined];

    return `${path}${path.includes('?') ? '&' : '?'}${search}${hash === undefined ? '' : `#${hash}`}`;
}
