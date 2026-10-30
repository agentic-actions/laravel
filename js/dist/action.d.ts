/**
 * The methods a definition may name: Inertia's five. callAction() sends a GET's input as the query string and every other
 * method's as the body. Generated definitions use POST, or GET for a Read on a mount with reads: 'get'.
 */
export type Method = 'get' | 'post' | 'put' | 'patch' | 'delete';
/** A typed action: structurally a UrlMethodPair, so Inertia's <Form action>, useForm and useHttp accept it. */
export type ActionDefinition<I = unknown, O = unknown> = {
    name: string;
    method: Method;
    url: string;
    touches: readonly string[];
    /** Phantom: carries the input and output types only; never set at runtime. */
    readonly __types?: {
        input: I;
        output: O;
    };
};
/** Define an action. The generated actions.ts calls it once per export. */
export declare function action<I, O>(definition: {
    name: string;
    method: Method;
    url: string;
    touches: readonly string[];
}): ActionDefinition<I, O>;
/** Fill {param} and {param?} segments of a route URI, URL-encoding each value. */
export declare function uri(template: string, params: Record<string, string | number>): string;
/** A v4 UUID. crypto.randomUUID() only exists in secure contexts; getRandomValues() everywhere. */
export declare function newKey(): string;
