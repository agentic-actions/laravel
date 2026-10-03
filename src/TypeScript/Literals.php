<?php

namespace AgenticActions\TypeScript;

/**
 * TypeScript literals shared by the emitter and the type writer: quoted strings, property keys and one-line comments.
 *
 * @internal
 */
trait Literals
{
    /**
     * A single-quoted TypeScript string literal.
     */
    private static function quote(string $value): string
    {
        return "'".str_replace(
            ['\\', "'", "\n", "\r", "\u{2028}", "\u{2029}"],
            ['\\\\', "\\'", '\\n', '\\r', '\\u2028', '\\u2029'],
            $value,
        )."'";
    }

    /**
     * A property key: bare when it is a valid identifier, otherwise quoted.
     */
    private static function key(string $key): string
    {
        return self::isIdentifier($key) ? $key : self::quote($key);
    }

    /**
     * Whether a string is a valid identifier name.
     */
    private static function isIdentifier(string $name): bool
    {
        return preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $name) === 1;
    }

    /**
     * A one-line JSDoc comment. Whitespace collapses to single spaces, and a closing sequence cannot end it early.
     */
    private static function comment(string $text): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', trim($text));

        return '/** '.str_replace('*/', '*\/', $text).' */';
    }
}
