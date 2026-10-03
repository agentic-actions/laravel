/** The methods a definition may name. No GET: callAction() sends the input as the body. Generated definitions use POST. */
export type Method = 'post' | 'put' | 'patch' | 'delete';

/** A typed action: structurally a UrlMethodPair, so Inertia's <Form action>, useForm and useHttp accept it. */
export type ActionDefinition<I = unknown, O = unknown> = {
    name: string;
    method: Method;
    url: string;
    touches: readonly string[];
    /** Phantom: carries the input and output types only; never set at runtime. */
    readonly __types?: { input: I; output: O };
};

/** Define an action. The generated actions.ts calls it once per export. */
export function action<I, O>(definition: { name: string; method: Method; url: string; touches: readonly string[] }): ActionDefinition<I, O> {
    return { name: definition.name, method: definition.method, url: definition.url, touches: definition.touches };
}

/** Fill {param} and {param?} segments of a route URI, URL-encoding each value. */
export function uri(template: string, params: Record<string, string | number>): string {
    let dropped = false;

    const filled = template.replace(/\{(\w+)(\?)?\}/g, (_segment: string, key: string, optional: string | undefined): string => {
        const value: string | number | undefined = params[key];

        if (value === undefined || value === null) {
            if (optional === undefined) {
                throw new Error(`Missing route parameter [${key}] for [${template}].`);
            }

            dropped = true;

            return '';
        }

        // A URL parser resolves "." and ".." segments, encoded or not, so either would move the call to another route.
        if (value === '.' || value === '..') {
            throw new Error(`Route parameter [${key}] for [${template}] cannot be "${value}".`);
        }

        return encodeURIComponent(String(value));
    });

    if (!dropped) {
        return filled;
    }

    // An absent optional segment leaves an empty path segment behind, which Laravel's own URL generator removes.
    const collapsed = filled.replace(/\/{2,}/g, '/');

    return collapsed.length > 1 && collapsed.endsWith('/') ? collapsed.slice(0, -1) : collapsed;
}

/** A v4 UUID. crypto.randomUUID() only exists in secure contexts; getRandomValues() everywhere. */
export function newKey(): string {
    if (typeof globalThis.crypto?.randomUUID === 'function') {
        return globalThis.crypto.randomUUID();
    }

    const bytes = globalThis.crypto.getRandomValues(new Uint8Array(16));

    bytes[6] = ((bytes[6] ?? 0) & 0x0f) | 0x40;
    bytes[8] = ((bytes[8] ?? 0) & 0x3f) | 0x80;

    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
