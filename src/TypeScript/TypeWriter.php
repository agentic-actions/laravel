<?php

namespace AgenticActions\TypeScript;

use Closure;

/**
 * Writes TypeScript types for serialized schema nodes. It never writes any or unknown: a shape the schema does
 * not declare becomes the local Json type.
 *
 * @internal
 */
final class TypeWriter
{
    use Literals;

    /**
     * Four spaces per level.
     */
    private const INDENT = '    ';

    /**
     * Create a writer.
     *
     * @param  (Closure(): void)|null  $usedJson  called each time a type refers to Json, so the emitter declares it once
     */
    public function __construct(private readonly ?Closure $usedJson = null) {}

    /**
     * A TypeScript type for a serialized schema node.
     *
     * @param  array<string, mixed>  $node
     */
    public function type(array $node, int $indent = 0): string
    {
        if (isset($node['anyOf']) && is_array($node['anyOf'])) {
            return $this->union(array_map(
                fn (mixed $branch): string => is_array($branch) ? $this->type($branch, $indent) : $this->json(),
                array_values($node['anyOf']),
            ));
        }

        $types = array_values(array_filter((array) ($node['type'] ?? []), is_string(...)));
        $nullable = in_array('null', $types, true);
        $types = array_values(array_diff($types, ['null']));

        if (isset($node['enum']) && is_array($node['enum']) && $node['enum'] !== []) {
            $members = array_map($this->literal(...), array_values($node['enum']));
        } elseif ($types === []) {
            $members = [$nullable ? 'null' : $this->json()];
        } else {
            $members = array_map(fn (string $type): string => $this->single($type, $node, $indent), $types);
        }

        if ($nullable) {
            $members[] = 'null';
        }

        return $this->union($members);
    }

    /**
     * An object type for a properties node, minus the given keys.
     *
     * @param  array<string, mixed>  $object
     * @param  list<string>  $without
     */
    public function object(array $object, array $without = [], int $indent = 0): string
    {
        $properties = is_array($object['properties'] ?? null) ? $object['properties'] : [];
        $required = is_array($object['required'] ?? null) ? $object['required'] : [];

        $lines = [];

        foreach ($properties as $key => $property) {
            $key = (string) $key;

            if (in_array($key, $without, true) || ! is_array($property)) {
                continue;
            }

            $pad = str_repeat(self::INDENT, $indent + 1);

            if (is_string($property['description'] ?? null) && trim($property['description']) !== '') {
                $lines[] = $pad.self::comment($property['description']);
            }

            $lines[] = $pad.self::key($key).(in_array($key, $required, true) ? '' : '?').': '.$this->type($property, $indent + 1).';';
        }

        if ($lines === []) {
            return 'Record<string, never>';
        }

        return "{\n".implode("\n", $lines)."\n".str_repeat(self::INDENT, $indent).'}';
    }

    /**
     * The type for one non-null JSON Schema type name.
     *
     * @param  array<string, mixed>  $node
     */
    private function single(string $type, array $node, int $indent): string
    {
        return match ($type) {
            'string' => ($node['format'] ?? null) === 'binary' ? 'File | Blob' : 'string',
            'integer', 'number' => 'number',
            'boolean' => 'boolean',
            'array' => 'Array<'.(is_array($node['items'] ?? null) ? $this->type($node['items'], $indent) : $this->json()).'>',
            'object' => is_array($node['properties'] ?? null) && $node['properties'] !== []
                ? $this->object($node, [], $indent)
                : 'Record<string, '.$this->json().'>',
            default => $this->json(),
        };
    }

    /**
     * A literal type for one enum value.
     */
    private function literal(mixed $value): string
    {
        return match (true) {
            is_string($value) => self::quote($value),
            is_int($value) => (string) $value,
            is_float($value) => (string) json_encode($value),
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default => $this->json(),
        };
    }

    /**
     * Join members with " | ", once each, in order. A member that is itself a union keeps its own members.
     *
     * @param  list<string>  $members
     */
    private function union(array $members): string
    {
        return implode(' | ', array_values(array_unique($members)));
    }

    /**
     * The local Json type, recording that it is used.
     */
    private function json(): string
    {
        if ($this->usedJson !== null) {
            ($this->usedJson)();
        }

        return 'Json';
    }
}
