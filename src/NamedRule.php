<?php

namespace AgenticActions;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Validation\Validator;
use WeakMap;

/**
 * A closure rule that reaches a model under its own name instead of "invalid".
 *
 * @api
 */
final class NamedRule implements ValidationRule, ValidatorAwareRule
{
    /**
     * The names that failed, by attribute, per validator. The validator keys every rule object by its class, so two
     * named rules on one key would otherwise collide.
     *
     * @var WeakMap<Validator, array<string, list<string>>>|null
     */
    private static ?WeakMap $failures = null;

    /**
     * The validator running this rule.
     */
    private ?Validator $validator = null;

    /**
     * A closure rule that reaches a model under its own name instead of "invalid".
     *
     * @param  Closure(mixed): bool  $passes
     */
    public function __construct(
        public readonly string $name,
        private readonly Closure $passes,
        private readonly ?string $message = null,
    ) {}

    /**
     * Run the closure and record the name against the validator when it fails.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (($this->passes)($value) === true) {
            return;
        }

        if ($this->validator !== null) {
            $failures = self::failures();
            $recorded = $failures[$this->validator] ?? [];
            $recorded[$attribute][] = $this->name;
            $failures[$this->validator] = $recorded;
        }

        $fail($this->message ?? 'The :attribute field is invalid.')->translate();
    }

    /**
     * Receive the running validator.
     */
    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    /**
     * The names that failed, by attribute, for one validator.
     *
     * @internal
     *
     * @return array<string, list<string>>
     */
    public static function failuresFor(Validator $validator): array
    {
        return self::failures()[$validator] ?? [];
    }

    /**
     * The failure map, created on first use.
     *
     * @return WeakMap<Validator, array<string, list<string>>>
     */
    private static function failures(): WeakMap
    {
        return self::$failures ??= new WeakMap;
    }
}
