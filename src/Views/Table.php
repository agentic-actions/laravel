<?php

namespace AgenticActions\Views;

use AgenticActions\ActionContext;
use AgenticActions\Exposure\Entry;
use AgenticActions\Schema\Projector;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * A table action's output, the one shape every surface gets, and the data-view part that shows it in the copilot.
 *
 * @internal
 *
 * @phpstan-type Output array{columns: list<array{key: string, label: string, type: string, currency?: string, decimals?: int, description?: string}>, rows: list<array<string, string|int|float|bool|null>>, truncated: bool, chart: array{type: string, x?: string, y: list<string>}, caption: string|null}
 */
final class Table
{
    /**
     * The chart types.
     */
    private const CHARTS = ['line', 'bar', 'metric', 'none'];

    /**
     * The most rows a bar chart draws.
     */
    private const BAR_ROWS = 50;

    /**
     * The output types, every key required and every node typed, so TypeScript and the projector read one exact shape.
     *
     * @param  list<Column>  $columns
     * @return array<string, Type>
     */
    public static function schema(JsonSchema $schema, array $columns): array
    {
        $keys = array_map(fn (Column $column): string => $column->key(), $columns);
        $row = [];

        foreach ($columns as $column) {
            $row[$column->key()] = $column->schema($schema);
        }

        return [
            'columns' => $schema->array()->items($schema->object([
                'key' => $schema->string()->enum($keys)->required(),
                'label' => $schema->string()->required(),
                'type' => $schema->string()->enum(Column::TYPES)->required(),
                'currency' => $schema->string(),
                'decimals' => $schema->integer(),
                'description' => $schema->string(),
            ]))->required(),
            'rows' => $schema->array()->items($schema->object($row))->required(),
            'truncated' => $schema->boolean()->required(),
            'chart' => $schema->object([
                'type' => $schema->string()->enum(self::CHARTS)->required(),
                'x' => $schema->string()->enum($keys),
                'y' => $schema->array()->items($schema->string()->enum($keys))->required(),
            ])->required(),
            'caption' => $schema->string()->nullable()->required(),
        ];
    }

    /**
     * A table output's row node: the columns' keys and types, which are its data. The output's other keys are the
     * package's own, so a check of the keys agents may receive reads this node alone.
     *
     * @param  array<string, mixed>  $output  the serialized output node of a table action
     * @return array<string, mixed>
     */
    public static function rowNode(array $output): array
    {
        $row = $output['properties']['rows']['items'] ?? null;

        return is_array($row) ? $row : ['type' => 'object', 'properties' => []];
    }

    /**
     * The action's columns, read now.
     *
     * @return list<Column>
     *
     * @throws InvalidArgumentException naming the class, when columns() declares no column, a key twice, or a column
     *                                  it cannot build
     */
    public static function columns(ShowsTable $action): array
    {
        try {
            $columns = $action->columns();
            $keys = array_map(fn (Column $column): string => $column->key(), $columns);

            if ($keys === [] || ($twice = array_diff_key($keys, array_unique($keys))) !== []) {
                throw new InvalidArgumentException($keys === [] ? 'columns() declares no column.' : 'columns() declares the key ['.reset($twice).'] twice.');
            }
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException($action::class.': '.$exception->getMessage(), previous: $exception);
        }

        return array_values($columns);
    }

    /**
     * A call's output, built inside its Read guard and locale: at most views.max_rows rows of the declared columns,
     * each read as the projector reads it and normalized to its column's type, truncated when there were more, and the
     * chart the columns' shape gives. A Rows result is taken as given: its rows, its chosen columns in declared order,
     * its truncated flag and its caption.
     *
     * @return Output
     *
     * @throws InvalidArgumentException when columns() does
     * @throws LogicException when handle() returned neither rows nor a query
     */
    public static function output(Entry $entry, ShowsTable $action, mixed $result): array
    {
        $max = (int) config('agentic-actions.views.max_rows', 500);
        $columns = self::columns($action);

        if ($result instanceof Rows) {
            $columns = array_values(array_filter($columns, fn (Column $column): bool => in_array($column->key(), $result->keys, true)));
            $rows = $result->rows;
        } else {
            $rows = self::read($entry, $result, $max + 1);
        }

        $cells = [];

        foreach (array_slice($rows, 0, $max) as $row) {
            $cell = [];

            foreach ($columns as $column) {
                $cell[$column->key()] = $column->normalize(Projector::read($row, $column->key())[1]);
            }

            $cells[] = $cell;
        }

        return [
            'columns' => array_map(fn (Column $column): array => $column->toArray(), $columns),
            'rows' => $cells,
            'truncated' => count($rows) > $max || ($result instanceof Rows && $result->truncated),
            'chart' => self::chart($columns, count($cells)),
            'caption' => $result instanceof Rows ? $result->caption : null,
        ];
    }

    /**
     * The data-view part showing a table output, for the stream and for a reload alike. It is rebuilt from an allowlist:
     * the action's name; the table's columns (key, label, type, currency, decimals, description) whose key passes the
     * key rule and whose type is one of the eight, their rows' scalar cells, a chart over those columns, the caption;
     * the time; the ref, only a ULID, given only when the caller found the table refreshable (RefreshView::allowed());
     * the refresh and truncated labels in the context's locale, the latter with its :count.
     *
     * @param  array<string, mixed>  $output
     * @return array{type: 'data-view', id: string, data: array<string, mixed>}
     */
    public static function part(Entry $entry, array $output, ActionContext $context, string $toolCallId, string $at, ?string $ref): array
    {
        $columns = [];

        foreach ((array) ($output['columns'] ?? []) as $column) {
            if (is_array($column) && is_string($key = $column['key'] ?? null) && preg_match(Column::KEY, $key) === 1
                && in_array($column['type'] ?? null, Column::TYPES, true) && is_string($column['label'] ?? null)) {
                $columns[$key] = [
                    'key' => $key,
                    'label' => $column['label'],
                    'type' => $column['type'],
                    ...(is_string($column['currency'] ?? null) ? ['currency' => $column['currency']] : []),
                    ...(is_int($column['decimals'] ?? null) ? ['decimals' => $column['decimals']] : []),
                    ...(is_string($column['description'] ?? null) ? ['description' => $column['description']] : []),
                ];
            }
        }

        $keys = array_keys($columns);
        $rows = [];

        foreach (array_slice((array) ($output['rows'] ?? []), 0, (int) config('agentic-actions.views.max_rows', 500)) as $row) {
            $rows[] = array_map(fn (string $key): mixed => is_array($row) && is_scalar($row[$key] ?? null) ? $row[$key] : null, array_combine($keys, $keys));
        }

        $chart = (array) ($output['chart'] ?? []);
        $truncated = ($output['truncated'] ?? false) === true;
        $locale = $context->locale === '' ? null : $context->locale;

        return ['type' => 'data-view', 'id' => 'view:'.$toolCallId, 'data' => [
            'action' => $entry->name,
            'table' => [
                'columns' => array_values($columns),
                'rows' => $rows,
                'truncated' => $truncated,
                'chart' => [
                    'type' => in_array($chart['type'] ?? null, self::CHARTS, true) ? $chart['type'] : 'none',
                    ...(in_array($chart['x'] ?? null, $keys, true) ? ['x' => $chart['x']] : []),
                    'y' => array_values(array_filter((array) ($chart['y'] ?? []), fn (mixed $key): bool => in_array($key, $keys, true))),
                ],
                'caption' => is_string($output['caption'] ?? null) ? $output['caption'] : null,
            ],
            'at' => $at,
            ...($ref !== null && Str::isUlid($ref) ? ['ref' => $ref] : []),
            // Both, always: the client puts its own row count in :count, after a refresh too.
            'labels' => [
                'refresh' => (string) trans('agentic-actions::views.refresh', [], $locale),
                'truncated' => (string) trans('agentic-actions::views.truncated', [], $locale),
            ],
        ]];
    }

    /**
     * At most $take rows of handle()'s result, an iterable or a query. A query is limited to $take rows before it runs,
     * unless it already carries a lower limit.
     *
     * @return list<mixed>
     *
     * @throws LogicException for any other result
     */
    private static function read(Entry $entry, mixed $result, int $take): array
    {
        if ($result instanceof Relation || $result instanceof EloquentBuilder || $result instanceof QueryBuilder) {
            $query = match (true) {
                $result instanceof Relation => $result->getBaseQuery(),
                $result instanceof EloquentBuilder => $result->getQuery(),
                default => $result,
            };

            $limit = $query->unions ? $query->unionLimit : $query->limit;

            if ($limit === null || $limit > $take) {
                $query->limit($take);
            }

            $result = $result->get();
        }

        if (! is_iterable($result)) {
            throw new LogicException("{$entry->class}: a table's handle() returns its rows, as an iterable of arrays or objects or as a query, not ".get_debug_type($result).'.');
        }

        $rows = [];

        foreach ($result as $row) {
            $rows[] = $row;

            if (count($rows) >= $take) {
                break;
            }
        }

        return $rows;
    }

    /**
     * The chart the columns' shape gives. One row of numeric columns only is a metric of them all. One non-numeric
     * column first, then numeric columns, is a line after a date or a date with a time, and a bar after text or yes or
     * no up to 50 rows. Anything else is none. A line's or a bar's y takes the numeric columns of the first numeric
     * column's type, so one chart has one unit.
     *
     * @param  list<Column>  $columns
     * @return array{type: string, x?: string, y: list<string>}
     */
    private static function chart(array $columns, int $rows): array
    {
        $numeric = array_values(array_filter($columns, fn (Column $column): bool => $column->numeric()));
        $first = $columns[0] ?? null;

        if ($first === null || $numeric === []) {
            return ['type' => 'none', 'y' => []];
        }

        $others = count($columns) - count($numeric);

        $type = match (true) {
            $others === 0 => $rows === 1 ? 'metric' : 'none',
            $others !== 1 || $first->numeric() => 'none',
            in_array($first->type(), ['date', 'datetime'], true) => 'line',
            $rows <= self::BAR_ROWS => 'bar',
            default => 'none',
        };

        $y = $type === 'metric' ? $numeric : array_filter($numeric, fn (Column $column): bool => $column->type() === $numeric[0]->type());

        return $type === 'none' ? ['type' => 'none', 'y' => []] : [
            'type' => $type,
            ...($type === 'metric' ? [] : ['x' => $first->key()]),
            'y' => array_values(array_map(fn (Column $column): string => $column->key(), $y)),
        ];
    }
}
