<?php

namespace AgenticActions\Datasets;

use AgenticActions\Views\Column;
use BackedEnum;
use InvalidArgumentException;

/**
 * One way a dataset's rows are grouped or filtered: a time column, a text column or a backed enum's column, of the
 * model or of one BelongsTo relation ("relation.column").
 *
 * @api
 */
final class Dimension
{
    /**
     * What the dimension holds, in words, if the dataset says.
     */
    private ?string $description = null;

    /**
     * Create a dimension. Only the named constructors below build one.
     *
     * @param  'time'|'text'|'enum'  $type
     * @param  class-string<BackedEnum>|null  $enum
     *
     * @throws InvalidArgumentException for a name or a column a dataset cannot read, or an enum that is not backed
     */
    private function __construct(
        /** @internal */
        public readonly string $name,
        /** @internal */
        public readonly string $label,
        /** @internal */
        public readonly string $type,
        /** @internal */
        public readonly string $column,
        /** @internal */
        public readonly ?string $enum = null,
    ) {
        self::check($name, $column);

        if ($enum !== null && ! is_subclass_of($enum, BackedEnum::class)) {
            throw new InvalidArgumentException("The dimension [{$name}] names [{$enum}], which is not a backed enum.");
        }
    }

    /**
     * The time the rows are counted in: one dataset has at most one, which a call groups by day, week (from Monday),
     * month, quarter or year, and limits to a range.
     */
    public static function time(string $name, string $label, string $column): self
    {
        return new self($name, $label, 'time', $column);
    }

    /**
     * A column of text, of the model or of one BelongsTo relation ("relation.column").
     */
    public static function text(string $name, string $label, string $column): self
    {
        return new self($name, $label, 'text', $column);
    }

    /**
     * A column holding a backed enum's values: a filter takes only those, and a row shows the case's label() when the
     * enum defines one.
     *
     * @param  class-string<BackedEnum>  $enum
     */
    public static function enum(string $name, string $label, string $column, string $enum): self
    {
        return new self($name, $label, 'enum', $column, $enum);
    }

    /**
     * Say what the dimension holds, for the model choosing it and as its column's description.
     */
    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Throw for a name that is not 1 to 41 lower-case letters, digits or underscores starting with a letter, or a
     * column that is neither one of the model's nor "relation.column".
     *
     * @internal
     *
     * @throws InvalidArgumentException
     */
    public static function check(string $name, ?string ...$columns): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,40}$/D', $name) !== 1) {
            throw new InvalidArgumentException("The name [{$name}] must be 1 to 41 lower-case letters, digits or underscores, starting with a letter.");
        }

        foreach (array_filter($columns, is_string(...)) as $column) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/D', $column) !== 1) {
                throw new InvalidArgumentException("[{$name}] reads [{$column}]: name a column of the model, or relation.column through a BelongsTo relation.");
            }
        }
    }

    /**
     * The table column the dimension answers under: a date for time, text otherwise.
     *
     * @internal
     */
    public function toColumn(): Column
    {
        $column = $this->type === 'time' ? Column::date($this->name, $this->label) : Column::text($this->name, $this->label);

        return $this->description === null ? $column : $column->description($this->description);
    }

    /**
     * The name, label and description, as the model reads them where it chooses the dimension.
     *
     * @internal
     */
    public function summary(): string
    {
        return "{$this->name}: {$this->label}".($this->description === null ? '' : ", {$this->description}");
    }

    /**
     * The enum's backing values, as strings, which a filter's values must be; none for any other dimension.
     *
     * @internal
     *
     * @return list<string>
     */
    public function values(): array
    {
        return $this->enum === null ? [] : array_map(fn (BackedEnum $case): string => (string) $case->value, $this->enum::cases());
    }

    /**
     * A value as the person reads it: an enum case's label() when the enum defines one, any other value as it is.
     *
     * @internal
     */
    public function show(mixed $value): mixed
    {
        foreach ($this->enum === null || ! is_scalar($value) ? [] : $this->enum::cases() as $case) {
            if ((string) $case->value === (string) $value) {
                return method_exists($case, 'label') ? $case->label() : $value;
            }
        }

        return $value;
    }
}
