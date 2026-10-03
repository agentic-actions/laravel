<?php

namespace AgenticActions\Views;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Stringable;

/**
 * One column of the table a Read action shows: its key, the header the person reads and the type its values take.
 *
 * @api
 */
final class Column
{
    /**
     * The eight types, in the order the docs list them.
     *
     * @internal
     */
    public const TYPES = ['text', 'integer', 'number', 'money', 'percent', 'date', 'datetime', 'boolean'];

    /**
     * What a key must match: lower-case snake case, at most 64 characters.
     *
     * @internal
     */
    public const KEY = '/^[a-z][a-z0-9_]{0,63}$/';

    /**
     * The most characters a text cell keeps; a longer one is cut, with an ellipsis.
     *
     * @internal
     */
    public const TEXT = 1000;

    /**
     * A date, alone or with a time, in ISO 8601 or the database's form: what a date or datetime string must be.
     */
    private const MOMENT = '/^(\d{4})-(\d{2})-(\d{2})(?:[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/';

    /**
     * What the column holds, in words, if the action says.
     */
    private ?string $description = null;

    /**
     * Create a column. Only the named constructors below build one.
     *
     * @throws InvalidArgumentException for a key that is not lower-case snake case, a currency that is not three
     *                                  upper-case letters, or decimals outside 0 to 20
     */
    private function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $type,
        private readonly ?string $currency = null,
        private readonly ?int $decimals = null,
    ) {
        if (preg_match(self::KEY, $key) !== 1) {
            throw new InvalidArgumentException("The column key [{$key}] must be 1 to 64 lower-case letters, digits or underscores, starting with a letter.");
        }

        if ($currency !== null && preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("The column [{$key}] has the currency [{$currency}]: give its ISO 4217 code, three upper-case letters such as USD.");
        }

        if ($decimals !== null && ($decimals < 0 || $decimals > 20)) {
            throw new InvalidArgumentException("The column [{$key}] has {$decimals} decimals: give 0 to 20.");
        }
    }

    /**
     * A column of text.
     */
    public static function text(string $key, string $label): self
    {
        return new self($key, $label, 'text');
    }

    /**
     * A column of whole numbers.
     */
    public static function integer(string $key, string $label): self
    {
        return new self($key, $label, 'integer');
    }

    /**
     * A column of numbers, shown with at most this many digits after the point.
     */
    public static function number(string $key, string $label, int $decimals = 2): self
    {
        return new self($key, $label, 'number', decimals: $decimals);
    }

    /**
     * A column of amounts in one currency, named by its ISO 4217 code.
     */
    public static function money(string $key, string $label, string $currency): self
    {
        return new self($key, $label, 'money', currency: $currency);
    }

    /**
     * A column of fractions, shown as percentages: 0.25 is 25%.
     */
    public static function percent(string $key, string $label): self
    {
        return new self($key, $label, 'percent');
    }

    /**
     * A column of dates, as Y-m-d.
     */
    public static function date(string $key, string $label): self
    {
        return new self($key, $label, 'date');
    }

    /**
     * A column of dates with a time, as ISO 8601.
     */
    public static function datetime(string $key, string $label): self
    {
        return new self($key, $label, 'datetime');
    }

    /**
     * A column of yes or no.
     */
    public static function boolean(string $key, string $label): self
    {
        return new self($key, $label, 'boolean');
    }

    /**
     * Say what the column holds; the table's columns carry it beside the label, for the model and the page.
     */
    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * The column's key in each row.
     */
    public function key(): string
    {
        return $this->key;
    }

    /**
     * One of the eight types.
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * Whether the column holds numbers: an integer, number, money or percent column.
     */
    public function numeric(): bool
    {
        return in_array($this->type, ['integer', 'number', 'money', 'percent'], true);
    }

    /**
     * The column as the table's output lists it.
     *
     * @return array{key: string, label: string, type: string, currency?: string, decimals?: int, description?: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            ...($this->currency === null ? [] : ['currency' => $this->currency]),
            ...($this->decimals === null ? [] : ['decimals' => $this->decimals]),
            ...($this->description === null ? [] : ['description' => $this->description]),
        ];
    }

    /**
     * The row property the column declares, nullable, with its description.
     */
    public function schema(JsonSchema $schema): Type
    {
        $type = match ($this->type) {
            'integer' => $schema->integer(),
            'number', 'money', 'percent' => $schema->number(),
            'boolean' => $schema->boolean(),
            default => $schema->string(),
        };

        return $this->description === null ? $type->nullable() : $type->nullable()->description($this->description);
    }

    /**
     * A value as this column's type holds it, or null: a number for a numeric column (an integer column's rounded), a
     * date as Y-m-d, a date with a time as ISO 8601, yes or no for a scalar, and for text a scalar, a backed enum's
     * value or a Stringable that is neither Arrayable nor Jsonable, as valid UTF-8 of at most TEXT characters.
     *
     * @internal
     */
    public function normalize(mixed $value): string|int|float|bool|null
    {
        return match ($this->type) {
            'integer', 'number', 'money', 'percent' => self::toNumber($value, $this->type === 'integer'),
            'date', 'datetime' => $this->moment($value),
            'boolean' => is_scalar($value) ? (bool) $value : null,
            default => match (true) {
                is_scalar($value) => self::clean((string) $value),
                $value instanceof BackedEnum => self::clean((string) $value->value),
                $value instanceof Stringable && ! $value instanceof Arrayable && ! $value instanceof Jsonable => self::clean((string) $value),
                default => null,
            },
        };
    }

    /**
     * A numeric value as a number, rounded for an integer column that is given a fraction; anything else, and a value
     * that is not finite, null.
     */
    private static function toNumber(mixed $value, bool $integer): int|float|null
    {
        if (! is_numeric($value) || ! is_finite($float = (float) $value)) {
            return null;
        }

        return $integer && ! is_int($value) && abs($float) < PHP_INT_MAX ? (int) round($float) : $value + 0;
    }

    /**
     * A date or a date with a time: a DateTimeInterface formatted; a string that is a real date, alone or with a time,
     * as Y-m-d for a date and read again as ISO 8601 for a datetime; anything else null.
     */
    private function moment(mixed $value): ?string
    {
        $date = $this->type === 'date';

        if ($value instanceof DateTimeInterface) {
            return $value->format($date ? 'Y-m-d' : DATE_ATOM);
        }

        if (! is_string($value) || preg_match(self::MOMENT, $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        if ($date) {
            return substr($value, 0, 10);
        }

        $moment = date_create_immutable($value);

        return $moment === false ? null : $moment->format(DATE_ATOM);
    }

    /**
     * Text as a cell keeps it: valid UTF-8, at most TEXT characters.
     */
    private static function clean(string $value): string
    {
        $text = mb_scrub($value, 'UTF-8');

        return mb_strlen($text) > self::TEXT ? mb_substr($text, 0, self::TEXT - 1).'…' : $text;
    }
}
