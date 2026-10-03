<?php

namespace AgenticActions\Datasets;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use AgenticActions\NamedRule;
use AgenticActions\Views\Column;
use AgenticActions\Views\Rows;
use AgenticActions\Views\ShowsTable;
use DateTimeZone;
use Exception;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Fluent;
use Illuminate\Support\Str;
use Illuminate\Support\ValidatedInput;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * A Read action that answers questions about one model's rows with a table: it declares dimensions and measures, and
 * the package generates its call, made only of the declared names, and writes a scoped, bounded query. Everything else
 * an action has holds: #[Expose], authorize(), shouldRegister(), $tenantScoped and the pipeline.
 *
 * @api
 *
 * @phpstan-type Declared array{0: Dimension|null, 1: array<string, Dimension>, 2: array<string, Measure>, 3: array<string, BelongsTo<Model, Model>>}
 */
abstract class Dataset extends Action implements ShowsTable
{
    /**
     * What since and until take, before they resolve to dates.
     */
    private const BOUNDS = [
        'since' => '^(-[1-9][0-9]{0,2}[dwmqy]|[0-9]{4}-[0-9]{2}-[0-9]{2})$',
        'until' => '^(today|[0-9]{4}-[0-9]{2}-[0-9]{2})$',
    ];

    /**
     * A dataset only reads; any other effect fails discovery.
     */
    protected ?Effect $effect = Effect::Read;

    /**
     * The model whose rows the dataset reads. Required.
     *
     * @var class-string<Model>
     */
    protected string $model;

    /**
     * The zone days, weeks and months are counted in; empty reads app.timezone.
     */
    protected string $timezone = '';

    /**
     * The first day when a call names none: "-30d", "-8w", "-6m", "-2q", "-1y" or a date.
     */
    protected string $range = '-30d';

    /**
     * The relations whose rows every tenant shares, such as countries or the users who write: inside a tenant they are
     * read without the tenant's scope, which every other related row is read in.
     *
     * @var list<string>
     */
    protected array $shared = [];

    /**
     * The ways rows are grouped and filtered: at most one Dimension::time(), and text or enum dimensions. Pure, with no
     * query, and read inside the call's locale, so labels may use __().
     *
     * @return list<Dimension>
     */
    abstract public function dimensions(): array;

    /**
     * The numbers a call can ask for. Pure, like dimensions().
     *
     * @return list<Measure>
     */
    abstract public function measures(): array;

    /**
     * Conditions on the model's rows, such as the actor's own: they are added in parentheses to the tenant's scope and
     * the model's global scopes, so they only narrow. Removing a scope, joining, grouping, ordering, limiting or
     * selecting fails the call.
     *
     * @param  Builder<Model>  $query
     */
    public function scope(Builder $query, ActionContext $context): void {}

    /**
     * The call, generated from the declarations: which measures, grouped by which dimensions or by a grain of the time,
     * over which days, filtered, compared with the previous period, sorted and limited.
     */
    final public function schema(JsonSchema $schema): array
    {
        [$time, $dimensions, $measures] = $this->declared();
        $summary = fn (array $declared): string => implode('; ', array_map(fn (Dimension|Measure $one): string => $one->summary(), $declared));
        $others = array_keys($dimensions);
        $max = (int) config('agentic-actions.views.max_rows', 500);

        return array_filter([
            'measures' => $schema->array()->items($schema->string()->enum(array_keys($measures)))->min(1)->max(5)->unique()->required()
                ->description('What to compute, one to five of: '.$summary($measures).'.'),
            'by' => $others === [] ? null : $schema->array()->items($schema->string()->enum($others))->max(2)->unique()->nullable()
                ->description('Group the rows by one or two of: '.$summary($dimensions).'.'),
            'grain' => $time === null ? null : $schema->string()->enum(Range::GRAINS)->nullable()
                ->description("One row per day, week (from Monday), month, quarter or year of {$time->summary()}, every one in the range; never with by."),
            'since' => $time === null ? null : $schema->string()->pattern(self::BOUNDS['since'])->nullable()
                ->description("The first day: -30d, -8w, -6m, -2q or -1y counts back to the start of that day, week, month, quarter or year, or a date as YYYY-MM-DD; by default {$this->range}."),
            'until' => $time === null ? null : $schema->string()->pattern(self::BOUNDS['until'])->nullable()
                ->description('The last day, included: today (the default) or a date as YYYY-MM-DD.'),
            'filters' => $others === [] ? null : $schema->array()->items($schema->object([
                'dimension' => $schema->string()->enum($others)->required(),
                'values' => $schema->array()->items($schema->string()->max(255))->min(1)->max(20)->required(),
                'exclude' => $schema->boolean()->nullable(),
            ]))->max(5)->nullable()->description('Keep the rows whose dimension is one of the values, or with exclude, none of them (rows with no value stay).'),
            'compare' => $time === null ? null : $schema->boolean()->nullable()
                ->description('Add each measure in the previous period of the same length, and its change.'),
            'sort' => $schema->string()->enum([...($time === null ? [] : [$time->name]), ...$others, ...array_keys($measures)])->nullable()
                ->description('Sort by a chosen measure or dimension, or by the time with grain; by default the time with grain, else the first measure.'),
            'ascending' => $schema->boolean()->nullable()
                ->description('Sort from the lowest; by default dimensions sort from the lowest and measures from the highest.'),
            'limit' => $schema->integer()->min(1)->max($max)->nullable()
                ->description('The most rows, 1 to '.$max.'; by default '.min(50, $max).'; with grain every bucket comes back.'),
        ]);
    }

    /**
     * What the generated schema cannot say: real dates between 1900 and 2999, since on or before until, a sort among
     * the chosen names, each dimension filtered once and an enum's own values, grain without by, and with grain at most
     * views.max_rows buckets.
     */
    final public function rules(ActionContext $context): array
    {
        [$time, $dimensions] = $this->declared();
        $dates = ['date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2999-12-31'];
        $max = (int) config('agentic-actions.views.max_rows', 500);

        $sortable = fn (Fluent $input): array => [
            ...(array) $input->get('measures'),
            ...(array) $input->get('by'),
            ...($time !== null && is_string($input->get('grain')) ? [$time->name] : []),
        ];

        $buckets = fn (Fluent $input): int => in_array($grain = $input->get('grain'), Range::GRAINS, true)
            ? rescue(fn (): int => count(Range::resolve((string) $input->get('since'), (string) $input->get('until'), $this->timezone())->buckets($grain, $max + 1)), 0, false)
            : 0;

        return [
            'sort' => [Rule::when(fn (Fluent $input): bool => ! in_array($input->get('sort'), $sortable($input), true), [self::refuse('chosen_measure_or_dimension')])],
            ...($dimensions === [] ? [] : [
                'filters.*' => [new NamedRule('dimension_values', fn (mixed $filter): bool => self::enumerated($filter, $dimensions))],
                'filters.*.dimension' => ['distinct'],
            ]),
            ...($time === null ? [] : [
                'since' => [...$dates, 'before_or_equal:until'],
                'until' => $dates,
                'grain' => ['prohibits:by', Rule::when(fn (Fluent $input): bool => $buckets($input) > $max, [self::refuse('coarser_grain_or_shorter_range', 'agentic-actions::views.buckets')])],
            ]),
        ];
    }

    /**
     * Since and until as dates in the dataset's zone, by default the dataset's range and today, so the checks and the
     * query read the days the call means and a refresh asks for the same ones.
     */
    final public function prepareForValidation(array $input, ActionContext $context): array
    {
        if ($this->declared()[0] === null) {
            return $input;
        }

        foreach (['since' => $this->range, 'until' => 'today'] as $key => $default) {
            $bound = $input[$key] ?? $default;

            if (is_string($bound) && preg_match('/'.self::BOUNDS[$key].'/D', $bound) === 1) {
                $input[$key] = Range::date($bound, $this->timezone());
            }
        }

        return $input;
    }

    /**
     * Every column a call can return, in order: the time as a date, the other dimensions as text, each measure, then
     * each measure's value in the previous period and its change, but a ratio's.
     */
    final public function columns(): array
    {
        [$time, $dimensions, $measures] = $this->declared();
        $compared = [];

        foreach ($measures as $measure) {
            if ($measure->kind !== 'ratio') {
                $compared[] = $measure->toColumn("{$measure->name}_previous", (string) trans('agentic-actions::views.previous', ['label' => $measure->label]));
                $compared[] = Column::percent("{$measure->name}_change", (string) trans('agentic-actions::views.change', ['label' => $measure->label]));
            }
        }

        return [
            ...array_map(fn (Dimension $dimension): Column => $dimension->toColumn(), array_values(array_filter([$time, ...$dimensions]))),
            ...array_map(fn (Measure $measure): Column => $measure->toColumn(), array_values($measures)),
            ...$compared,
        ];
    }

    /**
     * Answer the call: the rows the compiler reads, with the chosen columns, whether more rows existed, what was asked
     * and the dates it resolved to.
     */
    final public function handle(ActionContext $context, ValidatedInput $input, Compiler $compiler): Rows
    {
        return $compiler->run($this, $context, $input);
    }

    /**
     * The model class.
     *
     * @internal
     *
     * @return class-string<Model>
     *
     * @throws InvalidArgumentException naming the missing $model
     */
    final public function model(): string
    {
        if (! isset($this->model) || ! is_subclass_of($this->model, Model::class)) {
            throw new InvalidArgumentException('$model must name an Eloquent model class.');
        }

        return $this->model;
    }

    /**
     * The zone days are counted in: $timezone, else app.timezone.
     *
     * @internal
     *
     * @throws InvalidArgumentException for a zone PHP does not know
     */
    final public function timezone(): DateTimeZone
    {
        $zone = $this->timezone !== '' ? $this->timezone : (string) config('app.timezone', 'UTC');

        try {
            return new DateTimeZone($zone);
        } catch (Exception) {
            throw new InvalidArgumentException("The time zone [{$zone}] is unknown: give a name such as Europe/Berlin.");
        }
    }

    /**
     * The first day when a call names none.
     *
     * @internal
     */
    final public function range(): string
    {
        return $this->range;
    }

    /**
     * The relations read without the tenant's scope.
     *
     * @internal
     *
     * @return list<string>
     */
    final public function shared(): array
    {
        return $this->shared;
    }

    /**
     * The columns the declarations read, whatever names show them: the time's, each dimension's, and each measure's,
     * the column its where() compares included.
     *
     * @internal
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException for a declaration a dataset cannot answer
     */
    final public function columnsRead(): array
    {
        [$time, $dimensions, $measures] = $this->declared();

        return self::columnsOf($time, $dimensions, $measures);
    }

    /**
     * The declarations, checked: a model, a zone and a range that resolve; at most one time dimension; at least one
     * measure; each ratio of two other measures that are not ratios; every column key once; and each relation a public
     * method of the model declared to return exactly BelongsTo, found by reflection before it is called, joining the
     * related model's primary key and adding no conditions of its own; and each name $shared lists one of those relations.
     *
     * @internal
     *
     * @return Declared
     *
     * @throws InvalidArgumentException for a declaration a dataset cannot answer
     */
    final public function declared(): array
    {
        $model = $this->model();
        $this->timezone();

        if (preg_match('/'.self::BOUNDS['since'].'/D', $this->range) !== 1) {
            throw new InvalidArgumentException("\$range is [{$this->range}]: give -30d, -8w, -6m, -2q, -1y or a date as YYYY-MM-DD.");
        }

        [$time, $dimensions, $measures, $relations, $keys] = [null, [], [], [], []];

        foreach ($this->dimensions() as $dimension) {
            if ($dimension->type !== 'time') {
                $dimensions[$dimension->name] = $dimension;
            } elseif ($time === null) {
                $time = $dimension;
            } else {
                throw new InvalidArgumentException("[{$time->name}] and [{$dimension->name}] are both time dimensions: declare one.");
            }

            $keys[] = $dimension->name;
        }

        foreach ($this->measures() as $measure) {
            $measures[$measure->name] = $measure;
            array_push($keys, $measure->name, ...($measure->ratio === null ? ["{$measure->name}_previous", "{$measure->name}_change"] : []));
        }

        if ($measures === [] || ($twice = array_diff_key($keys, array_unique($keys))) !== []) {
            throw new InvalidArgumentException($measures === [] ? 'measures() declares no measure.' : 'The name ['.reset($twice).'] is declared twice.');
        }

        foreach ($measures as $measure) {
            $parts = array_map(fn (string $name): ?Measure => $measures[$name] ?? null, $measure->ratio ?? []);

            if (in_array(null, $parts, true) || array_filter($parts, fn (?Measure $part): bool => $part?->ratio !== null) !== []) {
                throw new InvalidArgumentException("The ratio [{$measure->name}] divides [".implode('] by [', $measure->ratio ?? []).']: name two measures of this dataset that are not ratios.');
            }
        }

        foreach (array_filter(self::columnsOf($time, $dimensions, $measures), fn (string $column): bool => str_contains($column, '.')) as $column) {
            $name = Str::before($column, '.');
            $relations[$name] ??= self::relation($model, $name);
        }

        foreach (array_diff($this->shared, array_keys($relations)) as $name) {
            throw new InvalidArgumentException("\$shared names [{$name}], which no dimension or measure reads through.");
        }

        return [$time, $dimensions, $measures, $relations];
    }

    /**
     * The columns a time, dimensions and measures read, each once: a measure's own and the one its where() compares.
     *
     * @param  array<string, Dimension>  $dimensions
     * @param  array<string, Measure>  $measures
     * @return list<string>
     */
    private static function columnsOf(?Dimension $time, array $dimensions, array $measures): array
    {
        $columns = [$time?->column];

        foreach ($dimensions as $dimension) {
            $columns[] = $dimension->column;
        }

        foreach ($measures as $measure) {
            array_push($columns, $measure->column, $measure->condition()[0] ?? null);
        }

        return array_values(array_unique(array_filter($columns, is_string(...))));
    }

    /**
     * A relation a dimension or measure reads through: a public method of the model declared to return exactly
     * BelongsTo, found by reflection before it is called, joining the related model's primary key and adding no
     * conditions of its own.
     *
     * @param  class-string<Model>  $model
     * @return BelongsTo<Model, Model>
     *
     * @throws InvalidArgumentException for any other method
     */
    private static function relation(string $model, string $name): BelongsTo
    {
        $method = method_exists($model, $name) ? new ReflectionMethod($model, $name) : null;
        $type = $method?->getReturnType();

        if ($method === null || ! $method->isPublic() || $method->isStatic() || ! $type instanceof ReflectionNamedType || $type->getName() !== BelongsTo::class) {
            throw new InvalidArgumentException("[{$name}] is not a relation a dataset reads: declare it on {$model} as a public method that returns BelongsTo.");
        }

        $relation = Relation::noConstraints(fn (): mixed => (new $model)->{$name}());

        if (! $relation instanceof BelongsTo || $relation::class !== BelongsTo::class || $relation->getOwnerKeyName() !== $relation->getRelated()->getKeyName()
            || $relation->getQuery()->getQuery()->wheres !== []) {
            throw new InvalidArgumentException("[{$name}] of {$model} must be a BelongsTo to the related model's primary key, with no conditions of its own.");
        }

        return $relation;
    }

    /**
     * Whether a filter's values are its enum dimension's own; a filter of any other dimension passes here.
     *
     * @param  array<string, Dimension>  $dimensions
     */
    private static function enumerated(mixed $filter, array $dimensions): bool
    {
        $dimension = is_array($filter) && is_string($filter['dimension'] ?? null) ? $dimensions[$filter['dimension']] ?? null : null;

        return $dimension?->enum === null || array_diff(array_filter((array) ($filter['values'] ?? []), is_scalar(...)), $dimension->values()) === [];
    }

    /**
     * A rule that refuses, under a name a model reads as the fix.
     */
    private static function refuse(string $name, ?string $message = null): NamedRule
    {
        return new NamedRule($name, fn (mixed $value): bool => false, $message);
    }
}
