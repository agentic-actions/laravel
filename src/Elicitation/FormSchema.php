<?php

namespace AgenticActions\Elicitation;

use AgenticActions\Approvals\ApprovalCard;
use Illuminate\Support\Str;
use Illuminate\Validation\ConditionalRules;
use Illuminate\Validation\Rules\Password;
use LogicException;

/**
 * One advertised field in MCP's restricted subset, or null when a form cannot hold it.
 *
 * @internal
 */
final class FormSchema
{
    /**
     * Words that make a field a secret, whatever agents.forbidden_keys says: MCP's "secrets and credentials that grant
     * access or authorize transactions" (passwords, API keys, access tokens, payment credentials).
     */
    private const SECRETS = ['password', 'passwd', 'pwd', 'passcode', 'passphrase', 'secret', 'token', 'credential',
        'credentials', 'apikey', 'pin', 'otp', 'totp', 'mfa', '2fa', 'cvv', 'cvv2', 'cvc', 'cvc2', 'iban'];

    /**
     * Word pairs that do the same, each word singular.
     */
    private const SECRET_PAIRS = ['api key', 'access key', 'private key', 'secret key', 'auth code', 'verification code',
        'recovery code', 'security code', 'card number', 'cc number', 'credit card', 'account number', 'routing number',
        'sort code', 'pass word', 'pass code', 'pass phrase', 'backup code', 'seed phrase', 'recovery phrase',
        'security answer'];

    /**
     * The string formats a form keeps, as the standard names them.
     */
    private const FORMATS = ['email' => 'email', 'uri' => 'uri', 'url' => 'uri', 'date' => 'date', 'date-time' => 'date-time'];

    /**
     * The field as a requestedSchema property and, for titled choices and numeric lists, each const => the value it
     * stands for; null when a form cannot hold it or it is a secret(). The default is the first candidate the field
     * accepts, else the node's own.
     *
     * @param  array<string, mixed>  $node  the advertised property node
     * @param  array{message: ?string, confirm: list<string>, choices: array<string, array<int|string, string>>, defaults: array<string, mixed>, widgets: array<string, 'textarea'>}  $ask  Ask::toArray()
     * @param  list<mixed>  $candidates  the model's accepted value, then Ask's default, when present
     * @param  list<mixed>  $rules  the field's own entry of rules(), a pipe string split into a list
     * @return array{0: array<string, mixed>, 1: array<string, int|float|string>}|null
     *
     * @throws LogicException when ask() gives the field a widget or a choice it cannot take
     */
    public static function property(string $key, array $node, array $ask, array $candidates, array $rules): ?array
    {
        $type = self::type($node);
        $title = ApprovalCard::text(is_string($node['title'] ?? null) ? $node['title'] : Str::headline($key));
        $description = is_string($node['description'] ?? null) ? ApprovalCard::text($node['description']) : null;

        if ($type === null || isset($node['anyOf']) || ($node['format'] ?? null) === 'binary' || self::secret($key, $title, (string) $description, $rules)) {
            return null;
        }

        if (isset($ask['widgets'][$key]) && $type !== 'string') {
            throw new LogicException("ask() shows [{$key}] as a textarea, which only a text field can be.");
        }

        $property = ['type' => $type, 'title' => $title, ...($description === null ? [] : ['description' => $description])];
        $candidates = array_key_exists('default', $node) ? [...$candidates, $node['default']] : $candidates;
        // Choices belong to a list's items, else to the field; numbers go out as const strings and map back.
        $holds = $type === 'array' ? (is_array($node['items'] ?? null) ? $node['items'] : []) : $node;
        $options = self::options($key, self::type($holds), $holds, $ask['choices'][$key] ?? null);
        $numeric = $options !== null && self::type($holds) !== 'string';
        $consts = array_column($options ?? [], 0);
        $values = array_column($options ?? [], 2);
        $choices = $numeric || isset($ask['choices'][$key])
            ? ['anyOf' => array_map(fn (array $option): array => ['const' => $option[0], 'title' => $option[1] ?? $option[0]], $options ?? [])]
            : ['type' => 'string', 'enum' => $consts];
        // A candidate is a value; its default goes out as that value's const.
        $picked = static fn (mixed $value): string => $consts[(int) array_search($value, $values, true)];
        $listed = static fn (mixed $value): bool => in_array($value, $values, true);

        if ($type === 'array') {
            if ($options === null) {
                return null;
            }

            $property += ['items' => $choices, ...array_intersect_key($node, ['minItems' => 0, 'maxItems' => 0])];
            $accepts = static fn (mixed $value): bool => is_array($value) && array_is_list($value) && array_filter($value, $listed) === $value;
            $default = static fn (array $value): array => array_map($picked, $value);
        } elseif ($options !== null) {
            // A single-select is a string: titled as oneOf, untitled as enum.
            $property['type'] = 'string';
            $property += isset($choices['anyOf']) ? ['oneOf' => $choices['anyOf']] : ['enum' => $consts];
            $accepts = $listed;
            $default = $picked;
        } else {
            $max = $node['maxLength'] ?? null;
            $property += match ($type) {
                'string' => [...array_intersect_key($node, ['minLength' => 0, 'maxLength' => 0]), ...(isset(self::FORMATS[$node['format'] ?? '']) ? ['format' => self::FORMATS[$node['format']]] : [])],
                'boolean' => [],
                default => array_intersect_key($node, ['minimum' => 0, 'maximum' => 0]),
            };
            $accepts = static fn (mixed $value): bool => match ($type) {
                // A default is shown as it is: never one holding a control, a line separator or a direction control.
                'string' => is_string($value) && (! is_int($max) || mb_strlen($value) <= $max)
                    && preg_match('/[^\P{Cc}\t\n\r]|[\p{Zl}\p{Zp}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $value) === 0,
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                default => is_bool($value),
            };
            $default = static fn (mixed $value): mixed => $value;
        }

        foreach ($candidates as $candidate) {
            if ($accepts($candidate)) {
                $property['default'] = $default($candidate);

                break;
            }
        }

        if (isset($ask['widgets'][$key])) {
            $property['x-agentic-actions'] = ['widget' => $ask['widgets'][$key]];
        }

        return [$property, $numeric ? array_combine($consts, $values) : []];
    }

    /**
     * Whether a form may never ask this field: its key, title or description holds a SECRETS word or a SECRET_PAIRS
     * pair (split on anything but letters and digits and at each case change, as in "pinCode" and "PINCode", each word
     * lowercased and singular, whole words only, so "shipping" and "opinion" pass), agents.forbidden_keys matches its
     * key, or one of its rules checks a password: current_password (with or without a guard, in a string or an array,
     * behind Rule::when()) or an Illuminate\Validation\Rules\Password.
     *
     * @param  list<mixed>  $rules
     */
    private static function secret(string $key, string $title, string $description, array $rules): bool
    {
        foreach ([$key, $title, $description] as $text) {
            $split = (string) preg_replace(['/(\p{Ll}|\p{N})(\p{Lu})/u', '/(\p{Lu})(\p{Lu}\p{Ll})/u'], '$1 $2', $text);
            $words = array_map(Str::singular(...), preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($split), -1, PREG_SPLIT_NO_EMPTY) ?: []);

            foreach ($words as $index => $word) {
                if (in_array($word, self::SECRETS, true) || in_array($word.' '.($words[$index + 1] ?? ''), self::SECRET_PAIRS, true)) {
                    return true;
                }
            }
        }

        foreach ((array) config('agentic-actions.agents.forbidden_keys', []) as $pattern) {
            if (Str::is(strtolower((string) $pattern), strtolower($key))) {
                return true;
            }
        }

        return self::checksPassword($rules);
    }

    /**
     * Whether a rule, or a list of rules at any depth, checks a password.
     */
    private static function checksPassword(mixed $rule): bool
    {
        return match (true) {
            $rule instanceof Password => true,
            is_string($rule) => Str::before($rule, ':') === 'current_password',
            $rule instanceof ConditionalRules => self::checksPassword([$rule->rules(), $rule->defaultRules()]),
            is_array($rule) => array_filter($rule, self::checksPassword(...)) !== [],
            default => false,
        };
    }

    /**
     * The node's one type, a nullable type read as its other type; null for an object, a union or no type.
     *
     * @param  array<string, mixed>  $node
     */
    private static function type(array $node): ?string
    {
        $types = array_values(array_diff((array) ($node['type'] ?? []), ['null']));

        return count($types) === 1 && in_array($types[0], ['string', 'integer', 'number', 'boolean', 'array'], true) ? $types[0] : null;
    }

    /**
     * The choices of a string, integer or number node as [const, title, value], from Ask's choices in their order, else
     * the node's enum; null when it has neither.
     *
     * @param  array<string, mixed>  $node
     * @param  array<int|string, string>|null  $choices
     * @return list<array{0: string, 1: ?string, 2: int|float|string}>|null
     *
     * @throws LogicException when a choice is not a value the field accepts
     */
    private static function options(string $key, ?string $type, array $node, ?array $choices): ?array
    {
        $enum = is_array($node['enum'] ?? null) ? array_values(array_filter($node['enum'], fn (mixed $value): bool => $value !== null)) : null;

        if (! in_array($type, ['string', 'integer', 'number'], true) || ($enum === null && $choices === null)) {
            return null;
        }

        $options = [];

        foreach ($choices === null ? array_map(fn (mixed $value): array => [$value, null], $enum ?? []) : array_map(null, array_keys($choices), $choices) as [$value, $label]) {
            $typed = match (true) {
                $type === 'string' => is_string($value) || is_int($value) ? (string) $value : null,
                is_int($value) => $value,
                $type === 'number' && is_numeric($value) => (float) $value,
                default => null,
            };

            if ($typed === null || ($enum !== null && ! in_array($typed, $enum, true))) {
                throw new LogicException("ask() gives [{$key}] the choice [".json_encode($value).'], which the field does not accept.');
            }

            $options[] = [(string) $typed, $label === null ? null : ApprovalCard::text((string) $label), $typed];
        }

        return $options;
    }
}
