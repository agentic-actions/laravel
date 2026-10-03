<?php

namespace AgenticActions\Schema;

use AgenticActions\Exceptions\UnsupportedSchema;
use AgenticActions\NamedRule;
use AgenticActions\Surface;
use Illuminate\Validation\Rule;

/**
 * Compiles a serialized schema into Laravel validation rules, keyed by dot path. Defaults are never merged.
 *
 * @internal
 */
final class RuleCompiler
{
    /**
     * The string formats and the rules that check them.
     *
     * @var array<string, string>
     */
    private const FORMATS = [
        'email' => 'email',
        'uri' => 'url',
        'url' => 'url',
        'uuid' => 'uuid',
        'date' => 'date_format:Y-m-d',
        'date-time' => 'date',
        'time' => 'date_format:H:i:s',
        'ipv4' => 'ipv4',
        'ipv6' => 'ipv6',
    ];

    /**
     * Laravel rules for a serialized object node.
     *
     * @param  array<string, mixed>  $object
     * @param  list<string>  $ownedByRules  top-level keys rules() defines, which may use shapes the compiler refuses
     * @return array<string, list<mixed>>
     *
     * @throws UnsupportedSchema
     */
    public function compile(array $object, Surface $surface, array $ownedByRules = []): array
    {
        $rules = [];

        $this->properties($object, '', 'top', $surface, $ownedByRules, $rules);

        return $rules;
    }

    /**
     * Compile each property of an object node.
     *
     * @param  array<string, mixed>  $object
     * @param  'top'|'nested'|'item'|'nullable item'  $position  a top-level key, a nested object's key, or a key of an object inside a list
     * @param  list<string>  $owned
     * @param  array<string, list<mixed>>  $rules
     */
    private function properties(array $object, string $parent, string $position, Surface $surface, array $owned, array &$rules): void
    {
        $properties = is_array($object['properties'] ?? null) ? $object['properties'] : [];
        $required = is_array($object['required'] ?? null) ? $object['required'] : [];

        foreach ($properties as $key => $property) {
            $key = (string) $key;
            $path = $parent === '' ? $key : "{$parent}.{$key}";
            $property = is_array($property) ? $property : [];
            $nullable = in_array('null', (array) ($property['type'] ?? []), true);

            $presence = $this->presence(in_array($key, $required, true), $nullable, $position, $parent);

            // Reserve the key first, so a parent's rules come before its children's.
            $rules[$path] = $presence;

            if ($position === 'top' && in_array($key, $owned, true) && $this->isUnion($property)) {
                continue;
            }

            $rules[$path] = [...$presence, ...$this->node($property, $path, $surface, $rules)];
        }
    }

    /**
     * The presence rules for one property. A key of a list item that may be null is excluded while the item is null.
     *
     * @param  'top'|'nested'|'item'|'nullable item'  $position
     * @return list<string>
     */
    private function presence(bool $required, bool $nullable, string $position, string $parent): array
    {
        if (! $required) {
            return $nullable ? ['sometimes', 'nullable'] : ['sometimes'];
        }

        return match ($position) {
            'nested' => $nullable ? ["present_with:{$parent}", 'nullable'] : ["required_with:{$parent}"],
            'nullable item' => ["exclude_if:{$parent},null", ...($nullable ? ['present', 'nullable'] : ['required'])],
            default => $nullable ? ['present', 'nullable'] : ['required'],
        };
    }

    /**
     * The type rules for one node; nested objects and list items add their own paths to $rules.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, list<mixed>>  $rules
     * @return list<mixed>
     */
    private function node(array $node, string $path, Surface $surface, array &$rules): array
    {
        if (isset($node['anyOf'])) {
            throw UnsupportedSchema::at($path, 'anyOf is not supported: declare one type, or let rules() own the key');
        }

        $types = array_values(array_diff((array) ($node['type'] ?? []), ['null']));

        if (count($types) > 1) {
            throw UnsupportedSchema::at($path, 'a union of '.implode(' and ', $types).' is not supported: declare one type, or let rules() own the key');
        }

        $type = $types[0] ?? null;

        $typeRules = match ($type) {
            'string' => $this->string($node, $path, $surface),
            'integer' => ['integer:strict', ...$this->bounds($node)],
            'number' => ['numeric:strict', new NamedRule('numeric', fn (mixed $value): bool => ! is_float($value) || is_finite($value), 'validation.numeric'), ...$this->bounds($node)],
            'boolean' => ['boolean:strict'],
            'array' => $this->list($node, $path, $surface, $rules),
            'object' => $this->object($node, $path, $surface, $rules),
            default => [],
        };

        if (is_array($node['enum'] ?? null)) {
            $typeRules[] = Rule::in($node['enum']);
        }

        return $typeRules;
    }

    /**
     * The rules for a string node.
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function string(array $node, string $path, Surface $surface): array
    {
        $format = isset($node['format']) ? (string) $node['format'] : null;

        if ($format === 'binary') {
            if ($surface !== Surface::Http) {
                throw UnsupportedSchema::at($path, 'format binary (a file) is accepted on HTTP only');
            }

            $rules = ['file'];
        } else {
            $rules = ['string'];
        }

        if (isset($node['minLength'])) {
            $rules[] = 'min:'.$node['minLength'];
        }

        if (isset($node['maxLength'])) {
            $rules[] = 'max:'.$node['maxLength'];
        }

        if (isset($node['pattern'])) {
            $rules[] = $this->pattern((string) $node['pattern'], $path);
        }

        if ($format !== null && $format !== 'binary') {
            $rules[] = self::FORMATS[$format] ?? throw UnsupportedSchema::at($path, "format {$format} is not supported");
        }

        return $rules;
    }

    /**
     * The rules for a list node, plus its items at "{path}.*".
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, list<mixed>>  $rules
     * @return list<string>
     */
    private function list(array $node, string $path, Surface $surface, array &$rules): array
    {
        $own = ['list'];

        if (isset($node['minItems'])) {
            $own[] = 'min:'.$node['minItems'];
        }

        if (isset($node['maxItems'])) {
            $own[] = 'max:'.$node['maxItems'];
        }

        $unique = ($node['uniqueItems'] ?? false) === true;

        if (is_array($node['items'] ?? null) || $unique) {
            $rules["{$path}.*"] = [];

            $items = is_array($node['items'] ?? null) ? $this->node($node['items'], "{$path}.*", $surface, $rules) : [];
            $nullable = in_array('null', (array) ($node['items']['type'] ?? []), true);

            if ($unique) {
                $items[] = 'distinct:strict';
            }

            $rules["{$path}.*"] = $nullable ? ['nullable', ...$items] : $items;

            if (is_array($node['items'] ?? null) && $this->isObject($node['items'])) {
                $this->properties($node['items'], "{$path}.*", $nullable ? 'nullable item' : 'item', $surface, [], $rules);
            }
        }

        return $own;
    }

    /**
     * The rules for an object node, plus its properties.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, list<mixed>>  $rules
     * @return list<string>
     */
    private function object(array $node, string $path, Surface $surface, array &$rules): array
    {
        $properties = is_array($node['properties'] ?? null) ? array_keys($node['properties']) : [];

        // A list item's properties are compiled by list(), which knows they sit under "{path}.*".
        if (! str_ends_with($path, '.*')) {
            $this->properties($node, $path, 'nested', $surface, [], $rules);
        }

        if (($node['additionalProperties'] ?? null) === false && $properties !== []) {
            return ['array:'.implode(',', $properties)];
        }

        return ['array'];
    }

    /**
     * The bound rules for a number or an integer node, after bail, so a value that failed a rule before them, its type
     * or the finite check, is never measured.
     *
     * @upstream A number that fails its type rules never reaches its bounds.
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function bounds(array $node): array
    {
        $rules = [];

        foreach (['minimum' => 'min', 'maximum' => 'max', 'multipleOf' => 'multiple_of'] as $keyword => $rule) {
            if (isset($node[$keyword])) {
                $rules[] = $rule.':'.$node[$keyword];
            }
        }

        return $rules === [] ? [] : ['bail', ...$rules];
    }

    /**
     * A regex rule for a JSON Schema pattern, with unescaped delimiters escaped. Lookarounds are refused.
     */
    private function pattern(string $pattern, string $path): string
    {
        foreach (['(?=', '(?!', '(?<=', '(?<!'] as $lookaround) {
            if (str_contains($pattern, $lookaround)) {
                throw UnsupportedSchema::at($path, "pattern lookaround {$lookaround} is not supported");
            }
        }

        $escaped = '';
        $backslashes = 0;

        foreach (str_split($pattern) as $character) {
            if ($character === '/' && $backslashes % 2 === 0) {
                $escaped .= '\\';
            }

            $backslashes = $character === '\\' ? $backslashes + 1 : 0;
            $escaped .= $character;
        }

        return "regex:/{$escaped}/uD";
    }

    /**
     * Whether a node declares more than one non-null type, or anyOf.
     *
     * @param  array<string, mixed>  $node
     */
    private function isUnion(array $node): bool
    {
        return isset($node['anyOf']) || count(array_diff((array) ($node['type'] ?? []), ['null'])) > 1;
    }

    /**
     * Whether a node declares an object.
     *
     * @param  array<string, mixed>  $node
     */
    private function isObject(array $node): bool
    {
        return in_array('object', (array) ($node['type'] ?? []), true);
    }
}
