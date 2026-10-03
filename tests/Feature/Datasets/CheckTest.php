<?php

use AgenticActions\ActionContext;
use AgenticActions\Console\Checks;
use AgenticActions\Console\Finding;
use AgenticActions\Datasets\Dimension;
use AgenticActions\Datasets\Measure;
use AgenticActions\Exceptions\MisconfiguredExposure;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Security\ForbiddenKeys;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Datasets\AccountPosts;
use Tests\Fixtures\Datasets\Invalid\BadDeclarations;
use Tests\Fixtures\Datasets\Invalid\NoModel;
use Tests\Fixtures\Datasets\Invalid\OddPost;
use Tests\Fixtures\Datasets\Invalid\WithAgentSchema;
use Tests\Fixtures\Datasets\Invalid\WritingDataset;
use Tests\Fixtures\Datasets\PostAuthors;
use Tests\Fixtures\Datasets\Posts;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * actions:check reads datasets: each declaration error with its message, a dataset that declares the schemas the
 * package generates or another effect than Read, the columns its dimensions and measures read against the output keys
 * agents may never receive, and its connection's driver and statement time limit.
 */

beforeEach(function () {
    [BadDeclarations::$dimensions, BadDeclarations::$measures, BadDeclarations::$days, BadDeclarations::$sharing, PostAuthors::$sharing] = [null, null, ['', '-30d'], [], []];
});

/**
 * The findings of one row for exactly these classes, as [level, row, message] triples.
 *
 * @param  list<class-string>  $classes
 * @return list<array{0: string, 1: string, 2: string}>
 */
function datasetFindings(array $classes, string $row): array
{
    config(['agentic-actions.discovery.paths' => [], 'agentic-actions.discovery.classes' => $classes]);

    return array_values(array_map(
        fn (Finding $finding): array => [$finding->level, $finding->row, $finding->message],
        array_filter(app(Checks::class)->run(), fn (Finding $finding): bool => $finding->row === $row),
    ));
}

it('reports a declaration a dataset cannot answer with its message, and leaves the dataset out of the other rows', function (Closure $declare, string $message) {
    $declare();

    expect(datasetFindings([BadDeclarations::class], 'Tables & datasets'))->toBe([['fail', 'Tables & datasets', BadDeclarations::class.': '.$message]])
        ->and(datasetFindings([BadDeclarations::class], 'Rules skipped'))->toBe([]);
})->with([
    'a morph as a relation' => [fn () => BadDeclarations::$dimensions = fn (): array => [Dimension::text('subject', 'Subject', 'subject.name')], '[subject] is not a relation a dataset reads: declare it on '.OddPost::class.' as a public method that returns BelongsTo.'],
    'a method that is no relation' => [fn () => BadDeclarations::$dimensions = fn (): array => [Dimension::text('saved', 'Saved', 'save.x')], '[save] is not a relation a dataset reads: declare it on '.OddPost::class.' as a public method that returns BelongsTo.'],
    'a shared name no declaration reads' => [fn () => BadDeclarations::$sharing = ['author'], '$shared names [author], which no dimension or measure reads through.'],
    'a relation to another key than the primary key' => [fn () => BadDeclarations::$dimensions = fn (): array => [Dimension::text('team', 'Team', 'teamBySlug.name')], '[teamBySlug] of '.OddPost::class.' must be a BelongsTo to the related model\'s primary key, with no conditions of its own.'],
    'a relation with conditions of its own' => [fn () => BadDeclarations::$dimensions = fn (): array => [Dimension::text('team', 'Team', 'namedTeam.name')], '[namedTeam] of '.OddPost::class.' must be a BelongsTo to the related model\'s primary key, with no conditions of its own.'],
    'a relation a measure reads' => [fn () => BadDeclarations::$measures = fn (): array => [Measure::countDistinct('subjects', 'Subjects', 'subject.id')], '[subject] is not a relation a dataset reads: declare it on '.OddPost::class.' as a public method that returns BelongsTo.'],
    'a name that is not snake case' => [fn () => BadDeclarations::$dimensions = fn (): array => [Dimension::text('Title', 'Title', 'title')], 'The name [Title] must be 1 to 41 lower-case letters, digits or underscores, starting with a letter.'],
    'a column that is an expression' => [fn () => BadDeclarations::$dimensions = fn (): array => [Dimension::text('title', 'Title', 'upper(title)')], '[title] reads [upper(title)]: name a column of the model, or relation.column through a BelongsTo relation.'],
    'two time dimensions' => [fn () => BadDeclarations::$dimensions = fn (): array => [Dimension::time('created', 'Created', 'created_at'), Dimension::time('updated', 'Updated', 'updated_at')], '[created] and [updated] are both time dimensions: declare one.'],
    'a name twice' => [fn () => BadDeclarations::$dimensions = fn (): array => [Dimension::text('posts', 'Posts', 'title')], 'The name [posts] is declared twice.'],
    'a name a comparison takes' => [fn () => BadDeclarations::$dimensions = fn (): array => [Dimension::text('posts_change', 'Change', 'title')], 'The name [posts_change] is declared twice.'],
    'a ratio of a measure it does not declare' => [fn () => BadDeclarations::$measures = fn (): array => [Measure::count('posts', 'Posts'), Measure::ratio('share', 'Share', 'published', 'posts')], 'The ratio [share] divides [published] by [posts]: name two measures of this dataset that are not ratios.'],
    'a ratio of a ratio' => [fn () => BadDeclarations::$measures = fn (): array => [Measure::count('posts', 'Posts'), Measure::ratio('one', 'One', 'posts', 'posts'), Measure::ratio('two', 'Two', 'one', 'posts')], 'The ratio [two] divides [one] by [posts]: name two measures of this dataset that are not ratios.'],
    'no measure' => [fn () => BadDeclarations::$measures = fn (): array => [], 'measures() declares no measure.'],
    'a condition on an average' => [fn () => BadDeclarations::$measures = fn (): array => [Measure::avg('average', 'Average', 'id')->where('status', 'draft')], 'The measure [average] takes no where(): only a count or a sum does.'],
    'money that is a count' => [fn () => BadDeclarations::$measures = fn (): array => [Measure::count('posts', 'Posts')->money('USD')], 'The measure [posts] cannot be money in [USD]: give a sum, average, minimum or maximum an ISO 4217 code such as USD.'],
    'a zone PHP does not know' => [fn () => BadDeclarations::$days = ['Mars/Olympus', '-30d'], 'The time zone [Mars/Olympus] is unknown: give a name such as Europe/Berlin.'],
    'a range that is no bound' => [fn () => BadDeclarations::$days = ['', 'yesterday'], '$range is [yesterday]: give -30d, -8w, -6m, -2q, -1y or a date as YYYY-MM-DD.'],
]);

it('reports a dataset that names no model', function () {
    expect(datasetFindings([NoModel::class], 'Tables & datasets'))->toBe([['fail', 'Tables & datasets', NoModel::class.': $model must name an Eloquent model class.']]);
});

it('fails, as its exposure, a dataset that declares the schemas the package generates, or that is not a Read', function (string $dataset, array $errors) {
    // Without laravel/ai, the Exposure row also names the agents toolset these fixtures ask for.
    expect(array_column(datasetFindings([$dataset], 'Exposure'), 2))->toContain(...array_map(fn (string $error): string => "{$dataset}: {$error}", $errors));
})->with([
    'agentSchema() and outputSchema()' => [WithAgentSchema::class, ['agentSchema(): a dataset\'s schemas are generated', 'outputSchema(): a dataset\'s schemas are generated']],
    'a Write' => [WritingDataset::class, ['a dataset is a Read action: remove its $effect']],
]);

it('reads the columns a dataset\'s dimensions and measures read against agents.forbidden_output_keys', function () {
    config(['agentic-actions.agents.forbidden_output_keys' => ['name', 'user_id']]);

    expect(datasetFindings([Posts::class], 'Model output'))->toBe([
        ['fail', 'Model output', Posts::class.': agents cannot receive the column [team.name] a dimension or measure reads: it matches agents.forbidden_output_keys. Remove it from dimensions() or measures().'],
        ['fail', 'Model output', Posts::class.': agents cannot receive the column [user_id] a dimension or measure reads: it matches agents.forbidden_output_keys. Remove it from dimensions() or measures().'],
    ]);
});

it('warns about a dataset connection with no statement time limit, and fails one on a driver datasets do not support', function (array $config, array $findings) {
    config($config);

    expect(datasetFindings([Posts::class], 'Tables & datasets'))->toBe($findings);
})->with([
    'SQLite' => [['database.connections.local' => ['driver' => 'sqlite', 'database' => ':memory:'], 'agentic-actions.datasets.connection' => 'local'], [['warn', 'Tables & datasets', 'The datasets [posts] run on the connection [local], which has no statement time limit (SQLite sets none), so a question runs as long as it takes. Give it one (docs/data.md#give-the-datasets-connection-a-time-limit).']]],
    'SQL Server' => [['database.connections.reports' => ['driver' => 'sqlsrv'], 'agentic-actions.datasets.connection' => 'reports'], [['fail', 'Tables & datasets', 'The datasets [posts] run on the connection [reports], whose driver [sqlsrv] datasets do not support: use SQLite, MySQL, MariaDB or Postgres.']]],
]);

it('reads the statement time limit the connection\'s session sets on MySQL, MariaDB and Postgres', function () {
    $driver = DB::connection()->getDriverName();

    if ($driver === 'sqlite') {
        $this->markTestSkipped('SQLite sets no statement time limit.');
    }

    // MariaDB names its limit max_statement_time, in seconds; MySQL max_execution_time, in milliseconds.
    [$setting, $set, $unset] = match ($driver) {
        'pgsql' => ['statement_timeout', "set statement_timeout = '10s'", null],
        'mariadb' => ['@@max_statement_time', 'set session max_statement_time = 10', 'set session max_statement_time = 0'],
        default => ['@@max_execution_time', 'set session max_execution_time = 10000', 'set session max_execution_time = 0'],
    };
    $without = datasetFindings([Posts::class], 'Tables & datasets');

    DB::statement($set);

    try {
        $with = datasetFindings([Posts::class], 'Tables & datasets');
    } finally {
        if ($unset !== null) {
            DB::statement($unset);
        }
    }

    expect($without)->toBe([['warn', 'Tables & datasets', 'The datasets [posts] run on the connection ['.config('database.default')."], which has no statement time limit ({$setting} is 0), so a question runs as long as it takes. Give it one (docs/data.md#give-the-datasets-connection-a-time-limit)."]])
        ->and($with)->toBe([]);
})->group('database');

it('reads the column a measure\'s where() compares against the keys agents may never receive', function () {
    $this->skipUnlessAi();

    config(['agentic-actions.agents.forbidden_output_keys' => ['*password*']]);
    BadDeclarations::$measures = fn (): array => [Measure::count('posts', 'Posts'), Measure::count('flagged', 'Flagged')->where('password', '')];

    expect(datasetFindings([BadDeclarations::class], 'Model output'))->toHaveCount(1);
});

it('leaves a dataset out of the agents\' tools when a declaration reads a forbidden column, whatever name shows it', function () {
    config([
        'agentic-actions.agents.forbidden_output_keys' => ['*password*'],
        'agentic-actions.discovery.paths' => [],
        'agentic-actions.discovery.classes' => [BadDeclarations::class],
    ]);
    BadDeclarations::$dimensions = fn (): array => [Dimension::text('contact', 'Contact', 'password')];
    $this->refreshActions();

    expect(fn () => app(ForbiddenKeys::class)->advertisable(ClassExposure::of(BadDeclarations::class), ActionContext::agent(User::factory()->create())))
        ->toThrow(MisconfiguredExposure::class, 'agents cannot receive the column [password] a dimension or measure reads');
});

it('warns about a dataset that is not tenant-scoped and declares no scope(), which counts every tenant\'s rows outside a tenant', function () {
    $this->useTeamTenancy();

    expect(datasetFindings([AccountPosts::class], 'Tables & datasets'))->toContain(['warn', 'Tables & datasets', AccountPosts::class.': it is not tenant-scoped and declares no scope(), so a call outside a tenant, such as an MCP client on the account\'s URL, counts every row of ['.Post::class.'], whichever tenant it belongs to. Add a scope() that keeps the rows its callers may count, or leave it tenant-scoped.'])
        ->and(collect(datasetFindings([Posts::class], 'Tables & datasets'))->pluck(2)->filter(fn (string $message): bool => str_contains($message, 'not tenant-scoped'))->all())->toBe([]);
});

it('fails a relation a dataset reads in the tenant\'s scope when the scope refuses it, and passes it once shared', function () {
    $this->useTeamTenancy();
    $refused = PostAuthors::class.': inside a tenant it reads [user] in the tenant\'s scope, which refuses it ('.User::class.': TeamScope cannot scope '.User::class.'.). Name it in $shared if every tenant shares those rows, or let the tenant scope scope them.';

    expect(datasetFindings([PostAuthors::class], 'Tables & datasets'))->toContain(['fail', 'Tables & datasets', $refused]);

    PostAuthors::$sharing = ['user'];

    expect(array_column(datasetFindings([PostAuthors::class], 'Tables & datasets'), 2))->not->toContain($refused);
});
