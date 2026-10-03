<?php

namespace AgenticActions\Views;

/**
 * Rows a table's handle() already chose, such as a dataset's answer: the table takes them as given, with the chosen
 * columns in the order columns() declares them, its truncated flag and its caption.
 *
 * @internal
 */
final class Rows
{
    /**
     * Create the rows.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $keys  the chosen columns' keys; the table shows them in the order columns() declares
     * @param  array<string, mixed>  $input  input keys the snapshot stores in place of the call's own, so a refresh
     *                                       answers the same question: a dataset's resolved dates
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $keys,
        public readonly bool $truncated = false,
        public readonly ?string $caption = null,
        public readonly array $input = [],
    ) {}

    /**
     * The rows of a table output as it was stored, to show them again under the columns an action declares now.
     *
     * @param  array<array-key, mixed>  $table
     */
    public static function of(array $table): self
    {
        $keys = array_map(fn (mixed $column): mixed => is_array($column) ? ($column['key'] ?? null) : null, (array) ($table['columns'] ?? []));

        return new self(
            array_values(array_filter((array) ($table['rows'] ?? []), is_array(...))),
            array_values(array_filter($keys, is_string(...))),
            ($table['truncated'] ?? false) === true,
            is_string($table['caption'] ?? null) ? $table['caption'] : null,
        );
    }
}
