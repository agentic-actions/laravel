<?php

namespace AgenticActions\Datasets;

use AgenticActions\ActionContext;
use AgenticActions\Approvals\ApprovalCard;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Refusal;
use AgenticActions\Tenancy\Tenants;
use AgenticActions\Views\Rows;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Support\ValidatedInput;
use LogicException;

/**
 * Writes and runs a dataset call. Every query has two levels: an inner Eloquent query over the model, in the tenant's
 * scope and the dataset's own conditions, joined to the relations it reads and filtered, selecting each value once
 * under an aa_ alias; and an outer query over it that groups and aggregates those aliases. Then a series' gaps are
 * filled, the previous period is added, and the caption says what was asked.
 *
 * @internal
 *
 * @phpstan-import-type Declared from Dataset
 *
 * @phpstan-type Call array{measures: list<string>, by: list<string>, grain: string|null, since: string, until: string, filters: list<array{dimension: string, values: list<string>, exclude: bool}>, compare: bool, sort: string, ascending: bool, limit: int}
 */
final class Compiler
{
    /**
     * Answer a call: its rows, the chosen columns, whether more rows existed, the caption, and the dates the call's
     * range resolved to, which a refresh asks for again.
     *
     * @throws Refusal status 409 when the connection's own time limit stopped a query
     * @throws LogicException when the tenant scope or scope() is not one a dataset can use
     */
    public function run(Dataset $dataset, ActionContext $context, ValidatedInput $input): Rows
    {
        $declared = $dataset->declared();
        [$time, $dimensions, $measures] = $declared;
        $call = self::call($input, $declared);
        $range = $time === null ? null : Range::resolve($call['since'], $call['until'], $dataset->timezone());
        $rows = $this->fetch($this->outer($this->inner($dataset, $context, $declared, $call, $range), $declared, $call, true), $call);
        $previous = $call['compare'] ? $range?->previous($call['grain']) : null;
        $truncated = $call['grain'] === null && count($rows) > $call['limit'];

        $rows = $call['grain'] === null || $range === null
            ? array_slice($rows, 0, $call['limit'])
            : $this->filled($rows, $range->buckets($call['grain']), $declared, $call);

        if ($previous !== null) {
            $groups = $call['by'] === [] ? null : array_map(fn (array $row): array => array_intersect_key($row, array_flip($call['by'])), $rows);
            $before = $groups === [] ? [] : $this->fetch($this->outer($this->inner($dataset, $context, $declared, $call, $previous, $groups), $declared, $call, false), $call);
            $rows = $this->compared($rows, $call['grain'] === null ? $before : $this->filled($before, $previous->buckets($call['grain']), $declared, $call), $declared, $call);
        }

        if ($call['grain'] !== null && ($call['sort'] !== $time?->name || ! $call['ascending'])) {
            usort($rows, fn (array $a, array $b): int => [$a[$call['sort']] === null, $call['ascending'] ? $a[$call['sort']] : $b[$call['sort']]]
                <=> [$b[$call['sort']] === null, $call['ascending'] ? $b[$call['sort']] : $a[$call['sort']]]);
        }

        foreach ($call['by'] as $name) {
            $rows = array_map(fn (array $row): array => [...$row, $name => $dimensions[$name]->show($row[$name])], $rows);
        }

        $keys = [...($call['grain'] === null || $time === null ? [] : [$time->name]), ...$call['by'], ...$call['measures']];

        foreach ($previous === null ? [] : $call['measures'] as $name) {
            array_push($keys, ...($measures[$name]->ratio === null ? ["{$name}_previous", "{$name}_change"] : []));
        }

        return new Rows($rows, $keys, $truncated, self::caption($declared, $call, $range, $previous), $range === null ? [] : ['since' => $call['since'], 'until' => $call['until']]);
    }

    /**
     * The call as the compiler reads it, with its defaults: the time in ascending order with grain, else the first
     * measure; dimensions from the lowest and measures from the highest; at most 50 rows or views.max_rows.
     *
     * @param  Declared  $declared
     * @return Call
     */
    private static function call(ValidatedInput $input, array $declared): array
    {
        [$time, , $measures] = $declared;
        $chosen = array_values(array_map(strval(...), (array) $input->input('measures')));
        $grain = is_string($input->input('grain')) ? $input->input('grain') : null;
        $sort = (string) ($input->input('sort') ?? ($grain !== null && $time !== null ? $time->name : $chosen[0]));

        return [
            'measures' => $chosen,
            'by' => array_values(array_map(strval(...), (array) $input->input('by'))),
            'grain' => $grain,
            'since' => (string) $input->input('since'),
            'until' => (string) $input->input('until'),
            'filters' => array_values(array_map(fn (mixed $filter): array => [
                'dimension' => (string) data_get($filter, 'dimension'),
                'values' => array_values(array_map(strval(...), (array) data_get($filter, 'values'))),
                'exclude' => data_get($filter, 'exclude') === true,
            ], (array) $input->input('filters'))),
            'compare' => $input->input('compare') === true,
            'sort' => $sort,
            'ascending' => is_bool($input->input('ascending')) ? $input->input('ascending') : ! isset($measures[$sort]),
            'limit' => (int) ($input->input('limit') ?? min(50, (int) config('agentic-actions.views.max_rows', 500))),
        ];
    }

    /**
     * The inner query: the model's rows in the tenant's scope whenever the call runs in a tenant, as find() reads them,
     * whether or not the dataset is tenant-scoped, and the dataset's own conditions; joined to each relation it reads
     * through that relation's own model and scopes, and in the tenant's scope too unless the dataset shares it; within
     * the range, filtered and, for the previous period, limited to
     * the current groups; selecting the bucket, each dimension and each measure's input once under an aa_ alias, and
     * for the previous period the index of the current group the database matched each row to, by its own collation.
     *
     * @param  Declared  $declared
     * @param  Call  $call
     * @param  list<array<string, mixed>>|null  $groups  the current groups' values, for the previous period
     * @return Builder<Model>
     *
     * @throws LogicException when the tenant scope answers for another model, or scope() does more than add conditions
     */
    private function inner(Dataset $dataset, ActionContext $context, array $declared, array $call, ?Range $range, ?array $groups = null): Builder
    {
        [$time, $dimensions, $measures, $relations] = $declared;
        $model = $dataset->model();
        $connection = config('agentic-actions.datasets.connection');
        $query = is_string($connection) && $connection !== '' ? $model::on($connection) : $model::query();

        $scoped = ClassExposure::of($dataset::class)->tenantScoped || (config('agentic-actions.tenant.model') !== null && $context->tenant !== null);

        if ($scoped) {
            $query = Tenants::scope($query, $context->tenant());

            if (! $query->getModel() instanceof $model) {
                throw new LogicException("The tenant scope returned a query for another model than [{$model}].");
            }
        }

        $instance = $query->getModel();
        $query->getQuery()->addNestedWhereQuery($this->own($dataset, $context, $instance));
        $grammar = $query->getQuery()->getGrammar();
        $column = fn (string $path): string => str_contains($path, '.') ? 'aa_'.str_replace('.', '.aa_', $path) : $instance->qualifyColumn($path);
        $inputs = array_intersect_key($measures, array_flip([...$call['measures'], ...array_merge([], ...array_map(fn (string $name): array => $measures[$name]->ratio ?? [], $call['measures']))]));
        $read = [...($range === null ? [] : [$time?->column]), ...array_map(fn (string $name): string => $dimensions[$name]->column, [...$call['by'], ...array_column($call['filters'], 'dimension')])];

        foreach ($inputs as $measure) {
            array_push($read, $measure->column, $measure->condition()[0] ?? null);
        }

        $joins = [];

        foreach (array_filter(array_unique($read), fn (?string $path): bool => str_contains((string) $path, '.')) as $path) {
            $joins[Str::before((string) $path, '.')][] = Str::after((string) $path, '.');
        }

        foreach ($joins as $name => $paths) {
            $related = $relations[$name]->getRelated()->setConnection($instance->getConnectionName());
            $rows = $scoped && ! in_array($name, $dataset->shared(), true) ? self::tenants($related, $context->tenant()) : $related->newQuery();
            $selects = array_map(fn (string $path): string => $related->qualifyColumn($path)." as aa_{$path}", $paths);

            $query->leftJoinSub($rows->select([$related->getQualifiedKeyName().' as aa_key', ...$selects]), "aa_{$name}", "aa_{$name}.aa_key", '=', $instance->qualifyColumn($relations[$name]->getForeignKeyName()));
        }

        if ($range !== null && $time !== null) {
            $storage = new DateTimeZone((string) config('app.timezone', 'UTC'));

            // A column cast to a date holds a day, not an instant: it is compared with the range's own days, never moved.
            $dated = str_contains($time->column, '.')
                ? self::dated($relations[Str::before($time->column, '.')]->getRelated(), Str::after($time->column, '.'))
                : self::dated($instance, $time->column);
            $format = $dated ? 'Y-m-d' : 'Y-m-d H:i:s';
            [$start, $end] = $dated ? [$range->start, $range->end] : [$range->start->setTimezone($storage), $range->end->setTimezone($storage)];

            $query->where($column($time->column), '>=', $start->format($format))
                ->where($column($time->column), '<', $end->format($format));

            if ($call['grain'] !== null) {
                [$bucket, $bindings] = Buckets::expression($instance->getConnection()->getDriverName(), $call['grain'], $grammar->wrap($column($time->column)), $dated ? [[$start->format('Y-m-d H:i:s'), 0]] : $range->segments($storage));
                $query->selectRaw("{$bucket} as aa_t", $bindings);
            }
        }

        foreach ($call['by'] as $name) {
            $query->addSelect($column($dimensions[$name]->column)." as aa_d_{$name}");
        }

        foreach ($inputs as $measure) {
            [$where, $value] = $measure->condition() ?? [null, null];
            $input = $measure->column === null ? '1' : $grammar->wrap($column($measure->column));

            match (true) {
                $where !== null => $query->selectRaw('case when '.$grammar->wrap($column($where))." = ? then {$input} end as aa_m_{$measure->name}", [$value]),
                $measure->column !== null => $query->addSelect($column($measure->column)." as aa_m_{$measure->name}"),
                default => null,
            };
        }

        foreach ($call['filters'] as $filter) {
            $path = $column($dimensions[$filter['dimension']]->column);

            $filter['exclude']
                ? $query->where(fn (Builder $query): Builder => $query->whereNotIn($path, $filter['values'])->orWhereNull($path))
                : $query->whereIn($path, $filter['values']);
        }

        if ($groups !== null) {
            // The database says which current group an earlier row belongs to, by the collation it grouped them by.
            [$cases, $bindings] = [[], []];

            foreach ($groups as $index => $group) {
                $conditions = [];

                foreach ($call['by'] as $name) {
                    $path = $grammar->wrap($column($dimensions[$name]->column));

                    if ($group[$name] === null) {
                        $conditions[] = "{$path} is null";
                    } else {
                        $conditions[] = "{$path} = ?";
                        $bindings[] = $group[$name];
                    }
                }

                $cases[] = 'when '.implode(' and ', $conditions).' then '.(int) $index;
            }

            $query->selectRaw('case '.implode(' ', $cases).' end as aa_g', $bindings);

            $query->where(function (Builder $query) use ($groups, $call, $dimensions, $column): void {
                foreach ($groups as $group) {
                    $query->orWhere(function (Builder $query) use ($group, $call, $dimensions, $column): void {
                        foreach ($call['by'] as $name) {
                            $query->where($column($dimensions[$name]->column), '=', $group[$name]);
                        }
                    });
                }
            });
        }

        return $query->getQuery()->columns === null ? $query->selectRaw('1 as aa_one') : $query;
    }

    /**
     * A related model's rows in the tenant's scope, as find() would read them: the tenant itself by its key, any other
     * model through the tenant scope. A row of another tenant then joins as none.
     *
     * @return Builder<Model>
     *
     * @throws LogicException when the tenant scope answers for another model
     */
    private static function tenants(Model $related, Model $tenant): Builder
    {
        if ($related instanceof $tenant) {
            return $related->newQuery()->whereKey($tenant->getKey());
        }

        $rows = Tenants::scope($related->newQuery(), $tenant);

        if (! $rows->getModel() instanceof $related) {
            throw new LogicException('The tenant scope returned a query for another model than ['.$related::class.'].');
        }

        return $rows;
    }

    /**
     * Whether the model casts the column to a date, with or without a format: a day, not an instant.
     */
    private static function dated(Model $model, string $column): bool
    {
        $cast = $model->getCasts()[$column] ?? null;

        return is_string($cast) && preg_match('/^(immutable_)?date(:|$)/', $cast) === 1;
    }

    /**
     * The dataset's own conditions: scope() on a query of its own, whose wheres alone join the call's query, nested.
     *
     * @throws LogicException naming the dataset, when scope() removed a scope or added anything but conditions
     */
    private function own(Dataset $dataset, ActionContext $context, Model $model): QueryBuilder
    {
        $own = $model->newQueryWithoutRelationships();
        $dataset->scope($own, $context);
        $base = $own->getQuery();

        if ($own->removedScopes() !== [] || $base->joins || $base->unions || $base->groups || $base->havings || $base->orders
            || $base->limit !== null || $base->offset !== null || $base->columns !== null) {
            throw new LogicException($dataset::class.': scope() may only add conditions, but it removed a scope, or added a join, union, group, having, order, limit, offset or columns.');
        }

        return $base;
    }

    /**
     * The outer query over the inner one: the bucket and each dimension under its name, each measure aggregated, grouped
     * by the aliases; the previous period's groups, by the current group each row was matched to. Without grain, the
     * current period's is also sorted, then by each group for a stable order, nulls last on every driver, and limited to
     * one row past the limit, which says whether more existed.
     *
     * @param  Builder<Model>  $inner
     * @param  Declared  $declared
     * @param  Call  $call
     */
    private function outer(Builder $inner, array $declared, array $call, bool $current): QueryBuilder
    {
        [$time, , $measures] = $declared;
        $outer = $inner->getQuery()->newQuery()->fromSub($inner, 'aa');
        $grammar = $outer->getGrammar();
        $matched = ! $current && $call['by'] !== [];
        $groups = $matched ? ['aa.aa_g'] : [...($call['grain'] === null ? [] : ['aa.aa_t']), ...array_map(fn (string $name): string => "aa.aa_d_{$name}", $call['by'])];

        if ($call['grain'] !== null && $time !== null) {
            $outer->addSelect("aa.aa_t as {$time->name}");
        }

        foreach ($matched ? [] : $call['by'] as $name) {
            $outer->addSelect("aa.aa_d_{$name} as {$name}");
        }

        if ($matched) {
            $outer->addSelect('aa.aa_g');
        }

        foreach ($call['measures'] as $name) {
            $outer->selectRaw(self::aggregate($measures, $name, $grammar).' as '.$grammar->wrap($name));
        }

        if ($groups !== []) {
            $outer->groupBy($groups);
        }

        if (! $current || $call['grain'] !== null) {
            return $outer;
        }

        if ($groups !== []) {
            $sort = isset($measures[$call['sort']]) ? self::aggregate($measures, $call['sort'], $grammar) : $grammar->wrap("aa.aa_d_{$call['sort']}");
            $outer->orderByRaw("{$sort} is null")->orderByRaw($sort.($call['ascending'] ? ' asc' : ' desc'));

            foreach ($groups as $group) {
                $outer->orderByRaw($grammar->wrap($group).' is null')->orderBy($group);
            }
        }

        return $outer->limit($call['limit'] + 1);
    }

    /**
     * A measure's aggregate over the inner aliases: a ratio of two others' aggregates in SQL, so it sorts, and never
     * divided by zero.
     *
     * @param  array<string, Measure>  $measures
     */
    private static function aggregate(array $measures, string $name, Grammar $grammar): string
    {
        $measure = $measures[$name];
        $input = $grammar->wrap("aa.aa_m_{$name}");

        return match ($measure->kind) {
            'count' => $measure->condition() === null ? 'count(*)' : "count({$input})",
            'countDistinct' => "count(distinct {$input})",
            'ratio' => self::aggregate($measures, $measure->ratio[0] ?? '', $grammar).' * 1.0 / nullif('.self::aggregate($measures, $measure->ratio[1] ?? '', $grammar).', 0)',
            default => "{$measure->kind}({$input})",
        };
    }

    /**
     * Run the outer query and read each measure as a number. A timeout the connection's own limit raised (MySQL 3024,
     * MariaDB 1969, Postgres 57014, known by its code since a server can word it in its own language) is refused with
     * the fixed sentence and not reported; any other failure fails the call.
     *
     * @param  Call  $call
     * @return list<array<string, mixed>>
     *
     * @throws Refusal status 409 for the timeout
     */
    private function fetch(QueryBuilder $outer, array $call): array
    {
        try {
            $rows = $outer->get();
        } catch (QueryException $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [3024, 1969], true) || $exception->getCode() === '57014') {
                throw Refusal::make('agentic-actions::views.too_slow')->status(409);
            }

            throw $exception;
        }

        return array_values($rows->map(function (object $row) use ($call): array {
            $row = (array) $row;

            foreach ($call['measures'] as $name) {
                $row[$name] = is_numeric($row[$name] ?? null) ? $row[$name] + 0 : null;
            }

            return $row;
        })->all());
    }

    /**
     * A series with every bucket of the range, in order: a bucket no row fell in gets 0 for a count or a sum, and null
     * for the rest.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $buckets
     * @param  Declared  $declared
     * @param  Call  $call
     * @return list<array<string, mixed>>
     */
    private function filled(array $rows, array $buckets, array $declared, array $call): array
    {
        [$time, , $measures] = $declared;
        $name = (string) $time?->name;
        $found = array_column($rows, null, $name);

        return array_map(fn (string $bucket): array => $found[$bucket] ?? [
            $name => $bucket,
            ...array_combine($call['measures'], array_map(fn (string $measure): ?int => $measures[$measure]->zero(), $call['measures'])),
        ], $buckets);
    }

    /**
     * Each row with every measure's previous value but a ratio's, matched by the current group the database matched the
     * earlier row to, or by the bucket's position, and its change as a fraction: none when the previous value is 0 or
     * none.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $before
     * @param  Declared  $declared
     * @param  Call  $call
     * @return list<array<string, mixed>>
     */
    private function compared(array $rows, array $before, array $declared, array $call): array
    {
        $measures = $declared[2];
        $key = fn (array $row, int $index): string => (string) ($row['aa_g'] ?? $index);
        $previous = [];

        foreach ($before as $index => $row) {
            $previous[$key($row, $index)] = $row;
        }

        foreach ($rows as $index => $row) {
            $then = $previous[$key($row, $index)] ?? null;

            foreach (array_filter($call['measures'], fn (string $name): bool => $measures[$name]->ratio === null) as $name) {
                $was = $then === null ? $measures[$name]->zero() : $then[$name];
                $rows[$index]["{$name}_previous"] = $was;
                $rows[$index]["{$name}_change"] = is_numeric($was) && $was != 0 && is_numeric($row[$name]) ? ($row[$name] - $was) / $was : null;
            }
        }

        return $rows;
    }

    /**
     * What was asked, in the call's locale: the measures, by the dimensions or the grain; the dates, and the previous
     * dates; each filter; and without grain the sort and the limit.
     *
     * @param  Declared  $declared
     * @param  Call  $call
     */
    private static function caption(array $declared, array $call, ?Range $range, ?Range $previous): string
    {
        [, $dimensions, $measures] = $declared;
        $labels = fn (array $names): string => implode(', ', array_map(fn (string $name): string => ($measures[$name] ?? $dimensions[$name])->label, $names));
        $by = $call['grain'] === null ? $labels($call['by']) : (string) trans('agentic-actions::views.'.$call['grain']);
        $parts = [$by === '' ? $labels($call['measures']) : (string) trans('agentic-actions::views.by', ['measures' => $labels($call['measures']), 'group' => $by])];

        foreach ([$range, $previous] as $index => $dates) {
            if ($dates !== null) {
                $days = implode(' – ', array_unique(array_map(fn ($day): string => $day->locale(app()->getLocale())->isoFormat('D MMM YYYY'), [$dates->start, $dates->end->subDay()])));
                $parts[] = $index === 0 ? $days : (string) trans('agentic-actions::views.compared', ['dates' => $days]);
            }
        }

        foreach ($call['filters'] as $filter) {
            $dimension = $dimensions[$filter['dimension']];
            $values = implode(', ', array_map(fn (string $value): string => $dimension->enum === null ? self::quoted($value) : (string) $dimension->show($value), $filter['values']));
            $parts[] = (string) trans($filter['exclude'] ? 'agentic-actions::views.is_not' : 'agentic-actions::views.is', ['dimension' => $dimension->label, 'values' => $values]);
        }

        if ($call['grain'] === null && $call['by'] !== []) {
            $parts[] = (string) trans($call['ascending'] ? 'agentic-actions::views.lowest' : 'agentic-actions::views.highest', ['count' => $call['limit'], 'measure' => $labels([$call['sort']])]);
        }

        return implode(' · ', $parts);
    }

    /**
     * A text filter's value as the caption shows it: quoted and cut to 40 characters, since the model chose it, so it
     * never reads as the package's own words. Plain text first, as a card's: no control, separator or direction
     * character reorders the caption around it, and a quotation mark inside it becomes an apostrophe, so the value
     * cannot close its own quotes and add a filter that was never applied.
     */
    private static function quoted(string $value): string
    {
        $value = str_replace(['“', '”', '"'], "'", ApprovalCard::text($value));

        return '“'.Str::limit((string) preg_replace('/\s+/u', ' ', $value), 40, '…').'”';
    }
}
