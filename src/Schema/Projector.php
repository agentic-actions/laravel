<?php

namespace AgenticActions\Schema;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use Stringable;
use UnitEnum;

/**
 * Reduces handle()'s result to the output schema's keys, at every depth. Undeclared keys never leave the server.
 *
 * @internal
 */
final class Projector
{
    /**
     * Reduce handle()'s result to the output schema's keys, at every depth.
     *
     * @param  array<string, mixed>  $object  the serialized output node
     * @return array<string, mixed>
     */
    public function project(mixed $result, array $object): array
    {
        /** @var array<string, mixed> */
        return self::object($result, $object);
    }

    /**
     * Read each declared property from an array or an object; a missing one is omitted.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function object(mixed $value, array $node): array
    {
        $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];
        $projected = [];

        foreach ($properties as $key => $property) {
            $key = (string) $key;

            [$found, $item] = self::read($value, $key);

            if ($found) {
                $projected[$key] = self::value($item, is_array($property) ? $property : []);
            }
        }

        return $projected;
    }

    /**
     * Project one value against its node.
     *
     * @param  array<string, mixed>  $node
     */
    private static function value(mixed $value, array $node): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if (isset($node['anyOf']) && is_array($node['anyOf'])) {
            return self::anyOf($value, $node['anyOf']);
        }

        $types = array_values(array_diff((array) ($node['type'] ?? []), ['null']));
        $list = is_iterable($value) && (! is_array($value) || array_is_list($value));

        // A union of types takes the one the value's shape declares: a list its list type before its object type.
        if (in_array('array', $types, true) && is_iterable($value) && ($list || ! in_array('object', $types, true))) {
            return self::list($value, $node);
        }

        if (in_array('object', $types, true) && (is_array($value) || is_object($value))) {
            return self::object($value, $node);
        }

        // Only a type other than an object or a list holds a scalar.
        if ($types !== [] && array_diff($types, ['object', 'array']) === []) {
            return null;
        }

        if (is_scalar($value)) {
            return $value;
        }

        if ($value instanceof Stringable && ! $value instanceof Arrayable && ! $value instanceof Jsonable) {
            return in_array('string', $types, true) ? (string) $value : null;
        }

        return null;
    }

    /**
     * Map a list's items node over an array or any iterable. Without an items node, only scalar items pass.
     *
     * @param  iterable<mixed>  $value
     * @param  array<string, mixed>  $node
     * @return list<mixed>
     */
    private static function list(iterable $value, array $node): array
    {
        $items = is_array($node['items'] ?? null) ? $node['items'] : null;
        $projected = [];

        foreach ($value as $item) {
            if ($items !== null) {
                $projected[] = self::value($item, $items);
            } elseif (is_scalar($item) || $item === null) {
                $projected[] = $item;
            }
        }

        return $projected;
    }

    /**
     * Project a value against the first anyOf branch declaring its shape: a list's array branch, else its object
     * branch; an object's object branch; a scalar's branch of another type. A nested anyOf's branches count as this
     * one's. Null when no branch declares the shape.
     *
     * @param  array<array-key, mixed>  $branches
     */
    private static function anyOf(mixed $value, array $branches): mixed
    {
        $shapes = match (true) {
            is_scalar($value) => ['scalar'],
            is_array($value) && array_is_list($value), is_iterable($value) && ! is_array($value) => ['array', 'object'],
            default => ['object'],
        };

        $branches = self::branches($branches);

        foreach ($shapes as $shape) {
            foreach ($branches as $branch) {
                $types = array_diff((array) ($branch['type'] ?? []), ['null']);

                if ($shape === 'scalar' ? array_diff($types, ['object', 'array']) !== [] : in_array($shape, $types, true)) {
                    return self::value($value, $branch);
                }
            }
        }

        return null;
    }

    /**
     * The branches of an anyOf, a nested anyOf's in its place.
     *
     * @param  array<array-key, mixed>  $branches
     * @return list<array<string, mixed>>
     */
    private static function branches(array $branches): array
    {
        $flat = [];

        foreach ($branches as $branch) {
            if (is_array($branch)) {
                $flat = [...$flat, ...(is_array($branch['anyOf'] ?? null) ? self::branches($branch['anyOf']) : [$branch])];
            }
        }

        return $flat;
    }

    /**
     * Read one key from a value: an array by key, a model through its attributes and loaded relations, anything else
     * with data_get(), never toArray(), so hidden and appended attributes never leak. A table reads its rows with it.
     *
     * @return array{0: bool, 1: mixed}
     */
    public static function read(mixed $value, string $key): array
    {
        if (is_array($value)) {
            return array_key_exists($key, $value) ? [true, $value[$key]] : [false, null];
        }

        if ($value instanceof Model) {
            try {
                $item = $value->getAttribute($key);
            } catch (MissingAttributeException) {
                return [false, null];
            }

            $found = $item !== null || $value->hasAttribute($key) || $value->relationLoaded($key);

            return [$found, $item];
        }

        if (! is_object($value)) {
            return [false, null];
        }

        $missing = new \stdClass;
        $item = data_get($value, $key, $missing);

        return $item === $missing ? [false, null] : [true, $item];
    }
}
