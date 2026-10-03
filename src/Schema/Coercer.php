<?php

namespace AgenticActions\Schema;

use AgenticActions\Surface;
use Illuminate\Support\Str;

/**
 * Converts strings to the types the schema declares, where no information is lost, so strict validation rules hold
 * for form posts and the command line.
 *
 * @internal
 */
final class Coercer
{
    /**
     * The keys TrimStrings leaves alone by default: a blank value for one stays a string, as on the web.
     */
    private const UNTRIMMED = ['current_password', 'password', 'password_confirmation'];

    /**
     * Convert strings to the types the schema declares, where no information is lost.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $object  the serialized object node
     * @return array<string, mixed>
     */
    public function coerce(array $input, array $object, Surface $surface): array
    {
        $formStyle = $surface === Surface::Http || $surface === Surface::Console;

        /** @var array<string, mixed> */
        return self::object(self::blanks($input), $object, $formStyle);
    }

    /**
     * What Laravel's TrimStrings and ConvertEmptyStringsToNull make of an empty or blank string on the web, for every
     * key at every depth, declared or not: '' reads as null, and so does a string of only whitespace unless its key is
     * one TrimStrings leaves alone. Any other string is kept as given, never trimmed; ltrim() reads a string only up to
     * its first other character.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    private static function blanks(array $input, string $prefix = ''): array
    {
        foreach ($input as $key => $value) {
            $input[$key] = match (true) {
                is_array($value) => self::blanks($value, $prefix.$key.'.'),
                $value === '' => null,
                is_string($value) && Str::ltrim($value) === '' && ! in_array($prefix.$key, self::UNTRIMMED, true) => null,
                default => $value,
            };
        }

        return $input;
    }

    /**
     * Coerce the declared properties of an object value; undeclared keys are left untouched.
     *
     * @param  array<array-key, mixed>  $value
     * @param  array<string, mixed>  $node
     * @return array<array-key, mixed>
     */
    private static function object(array $value, array $node, bool $formStyle): array
    {
        $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];

        foreach ($value as $key => $item) {
            if (is_string($key) && is_array($properties[$key] ?? null)) {
                $value[$key] = self::value($item, $properties[$key], $formStyle);
            }
        }

        return $value;
    }

    /**
     * Coerce one value against its node.
     *
     * @param  array<string, mixed>  $node
     */
    private static function value(mixed $value, array $node, bool $formStyle): mixed
    {
        // A union and a file are never touched.
        if (isset($node['anyOf']) || ($node['format'] ?? null) === 'binary') {
            return $value;
        }

        $types = array_values(array_diff((array) ($node['type'] ?? []), ['null']));

        if (is_array($value)) {
            return match (true) {
                in_array('object', $types, true) => self::object($value, $node, $formStyle),
                in_array('array', $types, true) && is_array($node['items'] ?? null) => array_map(
                    fn (mixed $item): mixed => self::value($item, $node['items'], $formStyle), $value,
                ),
                default => $value,
            };
        }

        if (! is_string($value)) {
            return $value;
        }

        return match ($types[0] ?? null) {
            'integer' => self::integer($value),
            'number' => self::number($value),
            'boolean' => self::boolean($value, $formStyle),
            default => $value,
        };
    }

    /**
     * An integer when the string is one that fits PHP's range, else the string.
     */
    private static function integer(string $value): int|string
    {
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            return $value;
        }

        $digits = ltrim(ltrim($value, '-'), '0');
        $canonical = $digits === '' ? '0' : (str_starts_with($value, '-') ? '-'.$digits : $digits);

        return (string) (int) $canonical === $canonical ? (int) $canonical : $value;
    }

    /**
     * A number when the string is numeric with no surrounding whitespace and finite, else the string.
     */
    private static function number(string $value): int|float|string
    {
        return is_numeric($value) && trim($value) === $value && is_finite($number = $value + 0) ? $number : $value;
    }

    /**
     * A boolean for "true" and "false" everywhere, and for "1", "on", "0" and "off" on form-style surfaces.
     */
    private static function boolean(string $value, bool $formStyle): bool|string
    {
        return match (true) {
            $value === 'true', $formStyle && ($value === '1' || $value === 'on') => true,
            $value === 'false', $formStyle && ($value === '0' || $value === 'off') => false,
            default => $value,
        };
    }
}
