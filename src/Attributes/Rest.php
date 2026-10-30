<?php

namespace AgenticActions\Attributes;

use Attribute;

/**
 * A REST-style route to the action, which Actions::rest() registers inside the caller's group: GET for a Read, POST,
 * PUT or PATCH for a Write, Destructive or External action, DELETE for a Destructive one (actions:check holds each
 * action to these). The route runs the same pipeline as the generated route. Repeat it for an alias. Read from the
 * class's own attributes, so it is never inherited, and it needs the web open (#[Expose] or #[Expose(web: true)]).
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Rest
{
    /**
     * Declare one route.
     *
     * @param  string  $method  GET, POST, PUT, PATCH or DELETE; GET also answers HEAD
     * @param  string  $path  relative to the group, such as "posts/{post}"; each parameter is an input key of schema()
     * @param  array<string, string>  $bind  path parameter => input key, where they differ, such as ['post' => 'post_id']
     * @param  bool  $created  answer a success with 201 instead of 200
     * @param  string|null  $location  with $created, the Location header: a path relative to the group, such as
     *                                 "posts/{id}", filled from the output's top-level keys and the route's parameters
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $bind = [],
        public readonly bool $created = false,
        public readonly ?string $location = null,
    ) {}

    /**
     * The method in upper case.
     */
    public function verb(): string
    {
        return strtoupper($this->method);
    }

    /**
     * The path without a leading or trailing slash.
     */
    public function uri(): string
    {
        return trim($this->path, '/');
    }

    /**
     * The path's parameter names, optional ones included, in order.
     *
     * @return list<string>
     */
    public function parameters(): array
    {
        preg_match_all('/\{(\w+)\??\}/', $this->path, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * The input key each path parameter fills: its $bind entry, else its own name.
     *
     * @return array<string, string>
     */
    public function keys(): array
    {
        $keys = [];

        foreach ($this->parameters() as $parameter) {
            $keys[$parameter] = $this->bind[$parameter] ?? $parameter;
        }

        return $keys;
    }
}
