<?php

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Schema\SchemaReader;
use AgenticActions\TypeScript\TypeWriter;
use AgenticActions\Views\Column;
use AgenticActions\Views\Rows;
use AgenticActions\Views\ShowsTable;
use AgenticActions\Views\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Tests\Fixtures\Views\PostsByDay;
use Tests\Fixtures\Views\PostStats;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * A table's output: the declared columns of at most views.max_rows rows, each value normalized to its column's type,
 * the chart the columns' shape gives, and the data-view part rebuilt from an allowlist.
 */

/**
 * The output of a table action whose columns() builds these columns, for this result.
 *
 * @param  Closure(): list<Column>  $columns
 * @return array<string, mixed>
 */
function tableOf(Closure $columns, mixed $result): array
{
    $action = new class($columns) extends Action implements ShowsTable
    {
        /**
         * Build the action on its columns.
         *
         * @param  Closure(): list<Column>  $declare
         */
        public function __construct(private readonly Closure $declare) {}

        /**
         * The columns, built now.
         */
        public function columns(): array
        {
            return ($this->declare)();
        }
    };

    return Table::output(ClassExposure::of(PostStats::class), $action, $result);
}

describe('rows', function () {
    it('keeps only the declared columns of each row, read as the projector reads arrays, objects and records', function () {
        $output = tableOf(fn (): array => [Column::text('title', 'Title'), Column::integer('words', 'Words')], [
            ['words' => 3, 'title' => 'First', 'secret' => 'CANARY-SECRET'],
            (object) ['title' => 'Second', 'secret' => 'CANARY-SECRET'],
            new Post(['title' => 'Third', 'body' => 'CANARY-BODY']),
        ]);

        expect($output['rows'])->toBe([
            ['title' => 'First', 'words' => 3],
            ['title' => 'Second', 'words' => null],
            ['title' => 'Third', 'words' => null],
        ])->and(json_encode($output, JSON_THROW_ON_ERROR))->not->toContain('CANARY');
    });

    it('normalizes each value to its column\'s type', function (Column $column, mixed $value, mixed $expected) {
        expect(tableOf(fn (): array => [$column], [[$column->key() => $value]])['rows'][0][$column->key()])->toBe($expected);
    })->with([
        'an integer column rounds a fraction' => [Column::integer('n', 'N'), '3.7', 4],
        'an integer stays exact' => [Column::integer('n', 'N'), PHP_INT_MAX, PHP_INT_MAX],
        'a numeric string is a number' => [Column::money('n', 'N', 'USD'), '19.99', 19.99],
        'a number that is not finite is null' => [Column::number('n', 'N'), INF, null],
        'words are no number' => [Column::percent('n', 'N'), 'a quarter', null],
        'yes is no number' => [Column::integer('n', 'N'), true, null],
        'a date from a date object' => [Column::date('d', 'D'), new DateTimeImmutable('2026-09-30 23:30:00'), '2026-09-30'],
        'a date from a longer string' => [Column::date('d', 'D'), '2026-09-30 10:00:00', '2026-09-30'],
        'no date from another format' => [Column::date('d', 'D'), '30/09/2026', null],
        'a date with a time as ISO 8601' => [Column::datetime('d', 'D'), new DateTimeImmutable('2026-09-30 10:00:00', new DateTimeZone('Asia/Damascus')), '2026-09-30T10:00:00+03:00'],
        'a date with a time from the database, read again as ISO 8601' => [Column::datetime('d', 'D'), '2026-09-30 10:00:00', '2026-09-30T10:00:00+00:00'],
        'no date with a time when anything follows it' => [Column::datetime('d', 'D'), '2026-09-30 10:00:00 <b>x</b>', null],
        'no date that does not exist' => [Column::date('d', 'D'), '2026-02-30', null],
        'yes or no from a scalar' => [Column::boolean('b', 'B'), 0, false],
        'no yes or no from a list' => [Column::boolean('b', 'B'), ['x'], null],
        'text from a scalar' => [Column::text('t', 'T'), 42, '42'],
        'text from a backed enum' => [Column::text('t', 'T'), Effect::Read, 'read'],
        'text from a Stringable' => [Column::text('t', 'T'), new HtmlString('<b>x</b>'), '<b>x</b>'],
        'no text from a record' => [Column::text('t', 'T'), fn (): User => User::factory()->make(['name' => 'CANARY']), null],
        'no text from a collection' => [Column::text('t', 'T'), collect(['CANARY']), null],
        'no text from a list' => [Column::text('t', 'T'), ['CANARY'], null],
        'text cut to 1,000 characters' => [Column::text('t', 'T'), str_repeat('a', 1001), str_repeat('a', 999).'…'],
        'text made valid UTF-8' => [Column::text('t', 'T'), "ab\xC3", 'ab?'],
        'null stays null' => [Column::integer('n', 'N'), null, null],
    ]);

    it('keeps at most views.max_rows rows, truncated when one more existed, and reads no further', function () {
        config(['agentic-actions.views.max_rows' => 2]);
        $read = 0;

        $rows = (function () use (&$read): Generator {
            while (true) {
                yield ['title' => 'Row '.++$read];
            }
        })();

        $title = fn (): array => [Column::text('title', 'Title')];
        $output = tableOf($title, $rows);

        expect($output['rows'])->toBe([['title' => 'Row 1'], ['title' => 'Row 2']])
            ->and($output['truncated'])->toBeTrue()
            ->and($read)->toBe(3)
            ->and(tableOf($title, [['title' => 'a'], ['title' => 'b']])['truncated'])->toBeFalse();
    });

    it('limits a query or a relation to views.max_rows + 1 rows before it runs, and keeps a lower limit', function () {
        config(['agentic-actions.views.max_rows' => 2]);
        $author = User::factory()->create();
        Post::factory()->count(5)->for($author)->create();
        $title = fn (): array => [Column::text('title', 'Title')];

        DB::enableQueryLog();

        $query = tableOf($title, Post::query()->orderBy('id'));
        $relation = tableOf($title, $author->posts()->orderBy('id'));
        $lower = tableOf($title, DB::table('posts')->orderBy('id')->limit(1));

        expect(array_map(fn (array $query): string => Str::afterLast($query['query'], ' '), DB::getQueryLog()))->toBe(['3', '3', '1'])
            ->and([count($query['rows']), $query['truncated']])->toBe([2, true])
            ->and([count($relation['rows']), $relation['truncated']])->toBe([2, true])
            ->and([count($lower['rows']), $lower['truncated']])->toBe([1, false]);
    });

    it('refuses a result that is neither rows nor a query', function () {
        expect(fn () => tableOf(fn (): array => [Column::text('title', 'Title')], 'CANARY'))
            ->toThrow(LogicException::class, 'a table\'s handle() returns its rows, as an iterable of arrays or objects or as a query, not string.');
    });

    it('takes Rows as given: its chosen columns in declared order, its truncated flag and its caption', function () {
        $output = tableOf(
            fn (): array => [Column::text('team', 'Team'), Column::integer('drafts', 'Drafts'), Column::integer('posts', 'Posts')],
            new Rows([['posts' => 3, 'team' => 'Acme', 'drafts' => 1, 'secret' => 'CANARY']], ['posts', 'team', 'secret'], truncated: true, caption: 'Posts by team'),
        );

        expect($output)->toBe([
            'columns' => [['key' => 'team', 'label' => 'Team', 'type' => 'text'], ['key' => 'posts', 'label' => 'Posts', 'type' => 'integer']],
            'rows' => [['team' => 'Acme', 'posts' => 3]],
            'truncated' => true,
            'chart' => ['type' => 'bar', 'x' => 'team', 'y' => ['posts']],
            'caption' => 'Posts by team',
        ]);
    });
});

it('lists each column with its description when it has one, for the model and the page', function () {
    $output = tableOf(fn (): array => [Column::text('title', 'Title'), Column::integer('words', 'Words')->description('Words in the body')], []);

    expect($output['columns'])->toBe([
        ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
        ['key' => 'words', 'label' => 'Words', 'type' => 'integer', 'description' => 'Words in the body'],
    ]);
});

describe('declarations', function () {
    it('refuses columns it cannot show, naming the class', function (Closure $columns, string $message) {
        expect(fn () => tableOf($columns, []))->toThrow(function (InvalidArgumentException $exception) use ($message): void {
            expect($exception->getMessage())->toStartWith(Action::class.'@anonymous')->toEndWith($message);
        });
    })->with([
        'a key that is not snake case' => [fn (): array => [Column::text('Title', 'Title')], ': The column key [Title] must be 1 to 64 lower-case letters, digits or underscores, starting with a letter.'],
        'a key given twice' => [fn (): array => [Column::text('title', 'Title'), Column::integer('title', 'Words')], ': columns() declares the key [title] twice.'],
        'a currency that is not ISO 4217' => [fn (): array => [Column::money('price', 'Price', 'usd')], ': The column [price] has the currency [usd]: give its ISO 4217 code, three upper-case letters such as USD.'],
        'decimals out of range' => [fn (): array => [Column::number('price', 'Price', -1)], ': The column [price] has -1 decimals: give 0 to 20.'],
        'no column' => [fn (): array => [], ': columns() declares no column.'],
    ]);
});

describe('the chart', function () {
    it('follows the columns\' shape', function (array $columns, int $rows, array $chart) {
        expect(tableOf(fn (): array => $columns, array_fill(0, $rows, []))['chart'])->toBe($chart);
    })->with([
        'one row of numbers is a metric of them all' => [[Column::integer('a', 'A'), Column::money('b', 'B', 'USD')], 1, ['type' => 'metric', 'y' => ['a', 'b']]],
        'two rows of numbers are none' => [[Column::integer('a', 'A'), Column::integer('b', 'B')], 2, ['type' => 'none', 'y' => []]],
        'a date then numbers is a line' => [[Column::date('d', 'D'), Column::integer('a', 'A'), Column::integer('b', 'B')], 5, ['type' => 'line', 'x' => 'd', 'y' => ['a', 'b']]],
        'a date with a time then a number is a line past 50 rows' => [[Column::datetime('d', 'D'), Column::number('a', 'A')], 51, ['type' => 'line', 'x' => 'd', 'y' => ['a']]],
        'text then numbers is a bar in the first number\'s unit' => [[Column::text('t', 'T'), Column::integer('a', 'A'), Column::percent('p', 'P'), Column::integer('c', 'C')], 3, ['type' => 'bar', 'x' => 't', 'y' => ['a', 'c']]],
        'yes or no then a number is a bar' => [[Column::boolean('b', 'B'), Column::number('n', 'N')], 50, ['type' => 'bar', 'x' => 'b', 'y' => ['n']]],
        'text then a number past 50 rows is none' => [[Column::text('t', 'T'), Column::integer('a', 'A')], 51, ['type' => 'none', 'y' => []]],
        'a number first is none' => [[Column::integer('a', 'A'), Column::text('t', 'T')], 2, ['type' => 'none', 'y' => []]],
        'two columns before the numbers are none' => [[Column::text('t', 'T'), Column::text('u', 'U'), Column::integer('a', 'A')], 2, ['type' => 'none', 'y' => []]],
        'no number is none' => [[Column::text('t', 'T')], 1, ['type' => 'none', 'y' => []]],
    ]);
});

describe('the schema', function () {
    it('types the output exactly, so TypeScript reads no loose object', function () {
        expect((new TypeWriter)->object(app(SchemaReader::class)->output(new PostsByDay)))->toBe(<<<'TS'
            {
                columns: Array<{
                    key: 'day' | 'posts';
                    label: string;
                    type: 'text' | 'integer' | 'number' | 'money' | 'percent' | 'date' | 'datetime' | 'boolean';
                    currency?: string;
                    decimals?: number;
                    description?: string;
                }>;
                rows: Array<{
                    day?: string | null;
                    posts?: number | null;
                }>;
                truncated: boolean;
                chart: {
                    type: 'line' | 'bar' | 'metric' | 'none';
                    x?: 'day' | 'posts';
                    y: Array<'day' | 'posts'>;
                };
                caption: string | null;
            }
            TS);
    });
});

describe('the part', function () {
    it('rebuilds the table from an allowlist, whatever the output holds', function () {
        $output = [
            'columns' => [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'secret' => 'CANARY', 'description' => ['CANARY']],
                ['key' => 'price', 'label' => 'Price', 'type' => 'money', 'currency' => 'USD', 'decimals' => '2', 'description' => 'What one costs'],
                ['key' => 'Bad Key', 'label' => 'Bad', 'type' => 'text'],
                ['key' => 'body', 'label' => 'Body', 'type' => 'html'],
            ],
            'rows' => [
                ['title' => 'First', 'price' => 9.5, 'Bad Key' => 'CANARY', 'body' => 'CANARY', 'secret' => 'CANARY'],
                ['title' => ['nested' => 'CANARY'], 'price' => null],
                'CANARY',
            ],
            'truncated' => 'yes',
            'chart' => ['type' => 'pie', 'x' => 'body', 'y' => ['price', 'body', ['CANARY']], 'series' => 'CANARY'],
            'caption' => ['CANARY'],
            'secret' => 'CANARY',
        ];

        expect(Table::part(ClassExposure::of(PostStats::class), $output, ActionContext::http(null), 'call_1', '2026-09-30T10:00:00+00:00', null))->toBe([
            'type' => 'data-view',
            'id' => 'view:call_1',
            'data' => [
                'action' => 'post-stats',
                'table' => [
                    'columns' => [['key' => 'title', 'label' => 'Title', 'type' => 'text'], ['key' => 'price', 'label' => 'Price', 'type' => 'money', 'currency' => 'USD', 'description' => 'What one costs']],
                    'rows' => [['title' => 'First', 'price' => 9.5], ['title' => null, 'price' => null], ['title' => null, 'price' => null]],
                    'truncated' => false,
                    'chart' => ['type' => 'none', 'y' => ['price']],
                    'caption' => null,
                ],
                'at' => '2026-09-30T10:00:00+00:00',
                'labels' => ['refresh' => 'Refresh', 'truncated' => 'Showing the first :count rows'],
            ],
        ]);
    });

    it('carries the ref it is given only as a ULID', function (string $class, string $ref, bool $carried) {
        $part = Table::part(ClassExposure::of($class), ['columns' => [], 'rows' => []], ActionContext::http(null), 'call_1', 'now', $ref);

        expect($part['data']['ref'] ?? null)->toBe($carried ? $ref : null);
    })->with([
        'a ULID on the web' => [PostStats::class, fn (): string => (string) Str::ulid(), true],
        'a lower-case ULID on the web' => [PostStats::class, fn (): string => strtolower((string) Str::ulid()), true],
        'anything else' => [PostStats::class, '../../admin', false],
    ]);

    it('always sends both labels in the context\'s locale, the truncated line with its :count for the page to fill', function (bool $truncated) {
        $part = Table::part(ClassExposure::of(PostStats::class), ['truncated' => $truncated], ActionContext::http(null, locale: 'ar'), 'call_1', 'now', null);

        expect($part['data']['labels'])->toBe(['refresh' => 'تحديث', 'truncated' => 'تُعرض أول :count من الصفوف']);
    })->with(['truncated' => true, 'whole' => false]);
});
