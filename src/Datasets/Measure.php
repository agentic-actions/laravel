<?php

namespace AgenticActions\Datasets;

use AgenticActions\Views\Column;
use BackedEnum;
use InvalidArgumentException;

/**
 * One number a dataset computes over its rows: a count, a count of distinct values, a sum, an average, a minimum, a
 * maximum, or the ratio of two other measures.
 *
 * @api
 */
final class Measure
{
    /**
     * The condition a count or a sum keeps rows by: a column and the value it equals.
     *
     * @var array{0: string, 1: string|int|bool}|null
     */
    private ?array $condition = null;

    /**
     * The currency of a sum, average, minimum or maximum of amounts.
     */
    private ?string $currency = null;

    /**
     * What the measure computes, in words, if the dataset says.
     */
    private ?string $description = null;

    /**
     * Create a measure. Only the named constructors below build one.
     *
     * @param  'count'|'countDistinct'|'sum'|'avg'|'min'|'max'|'ratio'  $kind
     * @param  array{0: string, 1: string}|null  $ratio  the numerator's and the denominator's names
     *
     * @throws InvalidArgumentException for a name or a column a dataset cannot read
     */
    private function __construct(
        /** @internal */
        public readonly string $name,
        /** @internal */
        public readonly string $label,
        /** @internal */
        public readonly string $kind,
        /** @internal */
        public readonly ?string $column = null,
        /** @internal */
        public readonly ?array $ratio = null,
    ) {
        Dimension::check($name, $column);
    }

    /**
     * The number of rows.
     */
    public static function count(string $name, string $label): self
    {
        return new self($name, $label, 'count');
    }

    /**
     * The number of distinct values of a column.
     */
    public static function countDistinct(string $name, string $label, string $column): self
    {
        return new self($name, $label, 'countDistinct', $column);
    }

    /**
     * The sum of a column.
     */
    public static function sum(string $name, string $label, string $column): self
    {
        return new self($name, $label, 'sum', $column);
    }

    /**
     * The average of a column.
     */
    public static function avg(string $name, string $label, string $column): self
    {
        return new self($name, $label, 'avg', $column);
    }

    /**
     * The smallest value of a column.
     */
    public static function min(string $name, string $label, string $column): self
    {
        return new self($name, $label, 'min', $column);
    }

    /**
     * The largest value of a column.
     */
    public static function max(string $name, string $label, string $column): self
    {
        return new self($name, $label, 'max', $column);
    }

    /**
     * One measure divided by another, both named, as a fraction: none when the denominator is 0.
     */
    public static function ratio(string $name, string $label, string $numerator, string $denominator): self
    {
        return new self($name, $label, 'ratio', ratio: [$numerator, $denominator]);
    }

    /**
     * Count, or sum, only the rows whose column equals the value.
     *
     * @throws InvalidArgumentException on a measure that is not a count or a sum, or for a column a dataset cannot read
     */
    public function where(string $column, BackedEnum|string|int|bool $value): self
    {
        if (! in_array($this->kind, ['count', 'sum'], true)) {
            throw new InvalidArgumentException("The measure [{$this->name}] takes no where(): only a count or a sum does.");
        }

        Dimension::check($this->name, $column);

        $this->condition = [$column, $value instanceof BackedEnum ? $value->value : $value];

        return $this;
    }

    /**
     * Show the values as amounts in one currency, named by its ISO 4217 code.
     *
     * @throws InvalidArgumentException on a count or a ratio, or for a currency that is not three upper-case letters
     */
    public function money(string $currency): self
    {
        if (! in_array($this->kind, ['sum', 'avg', 'min', 'max'], true) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException("The measure [{$this->name}] cannot be money in [{$currency}]: give a sum, average, minimum or maximum an ISO 4217 code such as USD.");
        }

        $this->currency = $currency;

        return $this;
    }

    /**
     * Say what the measure computes, for the model choosing it and as its column's description.
     */
    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * The condition set by where(), if any.
     *
     * @internal
     *
     * @return array{0: string, 1: string|int|bool}|null
     */
    public function condition(): ?array
    {
        return $this->condition;
    }

    /**
     * The value where no row counted: 0 for a count or a sum, none for the rest.
     *
     * @internal
     */
    public function zero(): ?int
    {
        return in_array($this->kind, ['count', 'countDistinct', 'sum'], true) ? 0 : null;
    }

    /**
     * The table column the measure answers under, by its own key and label or another's: an integer for a count, a
     * percent for a ratio, money with a currency, else a number.
     *
     * @internal
     */
    public function toColumn(?string $key = null, ?string $label = null): Column
    {
        [$key, $label] = [$key ?? $this->name, $label ?? $this->label];

        $column = match (true) {
            in_array($this->kind, ['count', 'countDistinct'], true) => Column::integer($key, $label),
            $this->kind === 'ratio' => Column::percent($key, $label),
            $this->currency !== null => Column::money($key, $label, $this->currency),
            default => Column::number($key, $label),
        };

        return $this->description === null ? $column : $column->description($this->description);
    }

    /**
     * The name, label and description, as the model reads them where it chooses the measure.
     *
     * @internal
     */
    public function summary(): string
    {
        return "{$this->name}: {$this->label}".($this->description === null ? '' : ", {$this->description}");
    }
}
