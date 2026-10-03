<?php

namespace Tests\Feature\Security;

use AgenticActions\ActionContext;
use AgenticActions\Facades\Actions;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Streaming\AgenticView;
use AgenticActions\Streaming\Transcript;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Fixtures\Datasets\Posts;
use Tests\Fixtures\Datasets\PostTitles;
use Tests\Fixtures\Streaming\ManyStepsGateway;
use Tests\Fixtures\Streaming\OneStepGateway;
use Tests\Fixtures\Streaming\Parts;
use Tests\Fixtures\Views\PostsByStatus;
use Tests\Fixtures\Views\PostStats;
use Tests\Fixtures\Views\ViewsAgent;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * A hostile caller against tables and datasets: a model's dataset call that names anything the dataset does not
 * declare, on any key and through any door; values that carry SQL, sent or stored; a query that fails. A ref someone
 * else was shown or one the person can no longer use, and a refresh that brings its own input. A reload after the
 * person lost the action or the app removed a column. A record in a text column, a tool-call id used again, and a model
 * that floods a turn with tables. Each attack gets everything right but the one thing its guard refuses.
 */

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'ai.conversations.generate_title' => false]);

    Auth::shouldUse('web');
});

afterEach(function () {
    ignore_user_abort(false);

    // Leave no hostile scope or withdrawn table behind for the next file's fixtures.
    Posts::$scope = null;
    PostsByStatus::reset();
});

/**
 * Run a request, and return what it answered with every statement it sent to the database.
 *
 * @template T
 *
 * @param  Closure(): T  $request
 * @return array{0: T, 1: list<string>}
 */
function withStatements(Closure $request): array
{
    $statements = [];

    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $answer = $request();

    return [$answer, $statements];
}

/**
 * The statements among these that read the posts table.
 *
 * @param  list<string>  $statements
 * @return list<string>
 */
function readingPosts(array $statements): array
{
    return array_values(array_filter($statements, fn (string $sql): bool => preg_match('/\bposts\b/', $sql) === 1));
}

/**
 * The input keys a refusal names, without their positions: measures for measures.0.
 *
 * @param  array<string, mixed>  $errors
 * @return list<string>
 */
function refusedKeys(array $errors): array
{
    return array_values(array_unique(array_map(fn (string $key): string => strtok($key, '.'), array_keys($errors))));
}

/**
 * The data-view parts among these parts, in order.
 *
 * @param  list<array<string, mixed>|string>  $parts
 * @return list<array<string, mixed>>
 */
function tablesAmong(array $parts): array
{
    return array_values(array_filter($parts, fn (array|string $part): bool => is_array($part) && $part['type'] === 'data-view'));
}

// Calls to the Posts dataset, each valid but for one key, and that key: SQL, an expression, a quoted identifier or an
// alias of the package's own query where a declared name goes, a value of another type, a cap passed, and days outside
// what the rules take.
$hostile = [
    'SQL as a measure' => [['measures' => ['posts) from users --']], 'measures'],
    'an aggregate as a measure' => [['measures' => ['count(*)']], 'measures'],
    'a list as a measure' => [['measures' => [['posts']]], 'measures'],
    'one measure twice' => [['measures' => ['posts', 'posts']], 'measures'],
    'measures as a map' => [['measures' => ['a' => 'posts']], 'measures'],
    'measures as a string' => [['measures' => 'posts'], 'measures'],
    'six measures' => [['measures' => ['posts', 'authors', 'published', 'published_share', 'posts_per_published', 'posts']], 'measures'],
    'SQL as a dimension' => [['measures' => ['posts'], 'by' => ['team; drop table posts']], 'by'],
    'a quoted identifier as a dimension' => [['measures' => ['posts'], 'by' => ['"title" from users --']], 'by'],
    'the time as a dimension' => [['measures' => ['posts'], 'by' => ['created']], 'by'],
    'an alias of the inner query as a dimension' => [['measures' => ['posts'], 'by' => ['aa_d_team']], 'by'],
    'three dimensions' => [['measures' => ['posts'], 'by' => ['team', 'status', 'team']], 'by'],
    'SQL as the grain' => [['measures' => ['posts'], 'grain' => "day') --"], 'grain'],
    'an hour as the grain' => [['measures' => ['posts'], 'grain' => 'hour'], 'grain'],
    'the grain in capitals' => [['measures' => ['posts'], 'grain' => 'DAY'], 'grain'],
    'SQL as the first day' => [['measures' => ['posts'], 'since' => "2026-01-01' or 1=1 --"], 'since'],
    'a first day with a trailing newline' => [['measures' => ['posts'], 'since' => "2026-01-01\n"], 'since'],
    'a relative first day with a trailing newline' => [['measures' => ['posts'], 'since' => "-30d\n"], 'since'],
    'no days back' => [['measures' => ['posts'], 'since' => '-0d'], 'since'],
    'a thousand days back' => [['measures' => ['posts'], 'since' => '-1000d'], 'since'],
    'days ahead' => [['measures' => ['posts'], 'since' => '+30d'], 'since'],
    'a list as the first day' => [['measures' => ['posts'], 'since' => ['2026-01-01']], 'since'],
    'a first day with a time' => [['measures' => ['posts'], 'since' => '2026-01-01 00:00:00'], 'since'],
    'yesterday as the last day' => [['measures' => ['posts'], 'until' => 'yesterday'], 'until'],
    'a last day in 3000' => [['measures' => ['posts'], 'until' => '3000-01-01'], 'until'],
    'a first day after the last' => [['measures' => ['posts'], 'since' => '2026-09-10', 'until' => '2026-09-01'], 'since'],
    'SQL as a filter\'s dimension' => [['measures' => ['posts'], 'filters' => [['dimension' => 'title) or 1=1 --', 'values' => ['x']]]], 'filters'],
    'the time as a filter\'s dimension' => [['measures' => ['posts'], 'filters' => [['dimension' => 'created', 'values' => ['2026-09-10']]]], 'filters'],
    'a list as a filter\'s value' => [['measures' => ['posts'], 'filters' => [['dimension' => 'team', 'values' => [['Blue']]]]], 'filters'],
    'a filter with no value' => [['measures' => ['posts'], 'filters' => [['dimension' => 'team', 'values' => []]]], 'filters'],
    'SQL as an enum dimension\'s value' => [['measures' => ['posts'], 'filters' => [['dimension' => 'status', 'values' => ["published' or '1'='1"]]]], 'filters'],
    'yes as exclude' => [['measures' => ['posts'], 'filters' => [['dimension' => 'team', 'values' => ['Blue'], 'exclude' => 'yes']]], 'filters'],
    'six filters' => [['measures' => ['posts'], 'filters' => array_fill(0, 6, ['dimension' => 'team', 'values' => ['Blue']])], 'filters'],
    'yes as compare' => [['measures' => ['posts'], 'compare' => 'yes'], 'compare'],
    'SQL as the sort' => [['measures' => ['posts'], 'sort' => 'posts desc, (select 1)'], 'sort'],
    'an alias of the inner query as the sort' => [['measures' => ['posts'], 'sort' => 'aa.aa_m_posts'], 'sort'],
    'the time as the sort without a grain' => [['measures' => ['posts'], 'sort' => 'created'], 'sort'],
    'a measure the call did not choose as the sort' => [['measures' => ['posts'], 'sort' => 'authors'], 'sort'],
    'no as ascending' => [['measures' => ['posts'], 'ascending' => 'no'], 'ascending'],
    'SQL as the limit' => [['measures' => ['posts'], 'limit' => '10; drop table posts'], 'limit'],
    'no rows as the limit' => [['measures' => ['posts'], 'limit' => 0], 'limit'],
    'more rows than a table holds' => [['measures' => ['posts'], 'limit' => 501], 'limit'],
    'a fraction as the limit' => [['measures' => ['posts'], 'limit' => 1.5], 'limit'],
    'a grain with a dimension' => [['measures' => ['posts'], 'grain' => 'day', 'by' => ['team']], 'grain'],
    'more days than a table holds' => [['measures' => ['posts'], 'grain' => 'day', 'since' => '2020-01-01'], 'grain'],
];

describe('a dataset call', function () use ($hostile) {
    beforeEach(function () {
        config([
            'agentic-actions.discovery.paths' => [],
            'agentic-actions.discovery.classes' => [Posts::class, PostTitles::class],
        ]);

        $this->refreshActions();
        [Posts::$scope, Posts::$zone] = [null, ''];

        $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
        $this->mountRoutes(fn () => Route::middleware(['web', 'auth'])->group(fn () => Actions::routes()));

        $this->user = User::factory()->create();
        Post::factory()->for($this->user)->forTeam(Team::factory()->create(['name' => 'Blue']))->create(['title' => 'Launch', 'status' => 'published', 'created_at' => '2026-09-10 10:00:00']);
    });

    it('refuses a call that names anything the dataset does not declare, naming the key, before any query reads posts', function (array $input, string $key) {
        [$outcome, $statements] = withStatements(fn () => Actions::attempt(Posts::class, $input, ActionContext::http($this->user)));

        expect($outcome->kind())->toBe(OutcomeKind::Invalid)
            ->and(refusedKeys($outcome->errors()))->toBe([$key])
            ->and(readingPosts($statements))->toBe([]);
    })->with($hostile);

    // The agent door keeps a list's positions and never its names, so a map of measures reaches the rules as a list.
    it('refuses the same calls through the agent door', function (array $input, string $key) {
        $this->skipUnlessAi();

        [$outcome, $statements] = withStatements(fn () => Actions::call(['default'], 'posts', $input, ActionContext::agent($this->user)));

        expect($outcome->kind())->toBe(OutcomeKind::Invalid)
            ->and(refusedKeys($outcome->errors()))->toBe([$key])
            ->and(readingPosts($statements))->toBe([]);
    })->with(array_diff_key($hostile, ['measures as a map' => true]));

    // On the web, the app's TrimStrings middleware trims a trailing newline away before the rules read the day.
    it('refuses the same calls on the web with 422, naming the key', function (array $input, string $key) {
        [$response, $statements] = withStatements(fn () => $this->actingAs($this->user)->postJson('/actions/posts', $input));

        $response->assertUnprocessable();

        expect(refusedKeys((array) $response->json('errors')))->toBe([$key])
            ->and(readingPosts($statements))->toBe([]);
    })->with(array_diff_key($hostile, ['a first day with a trailing newline' => true, 'a relative first day with a trailing newline' => true]));

    // From here on, the database group also runs each case on MySQL, MariaDB and Postgres, which quote, bind and fail
    // each in their own way.
    it('binds a filter value that carries SQL, in-process and on the web, and answers with it', function (string $value) {
        $input = ['measures' => ['posts', 'authors'], 'by' => ['title', 'team'], 'compare' => true, 'sort' => 'title', 'filters' => [
            ['dimension' => 'title', 'values' => [$value, 'Launch']],
            ['dimension' => 'team', 'values' => [$value], 'exclude' => true],
        ]];
        $rows = [['title' => 'Launch', 'team' => 'Blue', 'posts' => 1, 'authors' => 1, 'posts_previous' => 0, 'posts_change' => null, 'authors_previous' => 0, 'authors_change' => null]];

        [[$outcome, $response], $statements] = withStatements(fn (): array => [
            Actions::attempt(PostTitles::class, $input, ActionContext::http($this->user)),
            $this->actingAs($this->user)->postJson('/actions/post-titles', $input),
        ]);

        $response->assertOk()->assertJsonPath('rows', $rows);

        expect($outcome->output()['rows'] ?? null)->toBe($rows)
            ->and(array_filter($statements, fn (string $sql): bool => str_contains($sql, trim($value, " '\"\\"))))->toBe([]);
    })->with([
        'a quote' => ["x' or '1'='1"],
        'a double quote' => ['x" or "1"="1'],
        'a backslash' => ['x\\\' or 1=1 -- '],
        'a comment' => ['x/**/union/**/select/**/password/**/from/**/users--'],
        'a question mark' => ['x ? ?'],
        'a NUL' => ["x\0y"],
        'a dollar quote' => ['$$ or 1=1 $$'],
    ])->group('database');

    it('binds a stored value that carries SQL when the previous period asks for its group', function (string $value) {
        $team = Team::factory()->create(['name' => $value]);

        foreach (['2026-09-11 10:00:00', '2026-08-11 10:00:00'] as $created) {
            Post::factory()->for($this->user)->forTeam($team)->create(['title' => $value, 'created_at' => $created]);
        }

        [$outcome, $statements] = withStatements(fn () => Actions::attempt(PostTitles::class, ['measures' => ['posts'], 'by' => ['title', 'team'], 'since' => '-1m', 'compare' => true], ActionContext::http($this->user)));

        // The two groups tie, so the database's collation orders them: they are compared by title.
        $rows = array_column($outcome->output()['rows'] ?? [], null, 'title');
        $expected = [
            'Launch' => ['title' => 'Launch', 'team' => 'Blue', 'posts' => 1, 'posts_previous' => 0, 'posts_change' => null],
            $value => ['title' => $value, 'team' => $value, 'posts' => 1, 'posts_previous' => 1, 'posts_change' => 0],
        ];
        ksort($rows);
        ksort($expected);

        expect($rows)->toBe($expected)
            ->and(array_filter($statements, fn (string $sql): bool => str_contains($sql, trim($value, " '\"\\"))))->toBe([]);
    })->with([
        'a quote' => ["x' or '1'='1"],
        'a double quote' => ['x" or "1"="1'],
        'a backslash' => ['x\\\' or 1=1 -- '],
        'a dollar quote' => ['$$ or 1=1 $$'],
    ])->group('database');

    it('reports a query that fails for any reason but a time limit, and gives the model the fixed sentence', function () {
        Exceptions::fake();
        Posts::$scope = fn (Builder $query) => $query->where($query->qualifyColumn('no_such_column'), 1);

        $outcome = Actions::attempt(Posts::class, ['measures' => ['posts']], ActionContext::http($this->user));

        expect($outcome->kind())->toBe(OutcomeKind::Failed)
            ->and($outcome->forModel())->toBe(trans('agentic-actions::model.failed'));

        Exceptions::assertReported(QueryException::class);
    })->group('database');

    it('answers such a query on the web with the app\'s own 500, and no SQL', function () {
        config(['app.debug' => false]);
        Exceptions::fake();
        Posts::$scope = fn (Builder $query) => $query->where($query->qualifyColumn('no_such_column'), 1);

        $body = strtolower((string) $this->actingAs($this->user)->postJson('/actions/posts', ['measures' => ['posts']])->assertServerError()->getContent());

        expect($body)->not->toContain('no_such_column')
            ->and($body)->not->toContain('select');
    })->group('database');
});

describe('a refresh', function () {
    beforeEach(function () {
        $this->skipUnlessAi();
        $this->useTeamTenancy();

        config([
            'agentic-actions.discovery.paths' => [],
            'agentic-actions.discovery.classes' => [Posts::class, PostsByStatus::class],
        ]);

        $this->refreshActions();
        [Posts::$scope, Posts::$zone] = [null, ''];
        PostsByStatus::reset();

        $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
        $this->mountRoutes(function () {
            Route::middleware(['web', 'auth'])->prefix('teams/{team}')->name('teams.')->group(fn () => Actions::routes(tenant: true));
            Route::middleware(['web', 'auth'])->prefix('plain/{team}')->name('plain.')->group(fn () => Actions::routes(tenant: false));
            Route::middleware('auth:sanctum')->prefix('api/teams/{team}')->name('api.teams.')->group(fn () => Actions::routes(tenant: true));
        });

        $this->user = User::factory()->create();
        [$this->acme, $this->other] = [Team::factory()->create(['slug' => 'acme']), Team::factory()->create(['slug' => 'other'])];
        $this->user->teams()->attach([$this->acme->id, $this->other->id]);
        Post::factory()->for($this->user)->forTeam($this->acme)->create(['title' => 'Launch notes', 'status' => 'published', 'created_at' => '2026-09-10 10:00:00']);

        // A table the person was shown in Acme, in a turn of a new conversation, as it was kept.
        $this->shown = function (string $action, array $arguments): AgenticView {
            (new OneStepGateway([new ToolCall('call_1', $action, $arguments)], 'Replied.'))->fake(ViewsAgent::class);

            Parts::body((new ViewsAgent($this->user, $this->acme))->forUser($this->user)->stream('Show me.'));
            ignore_user_abort(false);

            return AgenticView::query()->sole();
        };
    });

    it('gives the person the table was shown to its rows again', function () {
        $view = ($this->shown)('posts', ['measures' => ['posts'], 'by' => ['status']]);

        $this->actingAs($this->user)->postJson("/teams/acme/actions/_views/{$view->id}")->assertOk()->assertJsonPath('table.rows', [['status' => 'Published', 'posts' => 1]]);
    });

    it('answers a ref the person may not refresh with the 404 of an unknown one, and reads no post', function (Closure $attack) {
        $view = ($this->shown)('posts', ['measures' => ['posts'], 'by' => ['status']]);

        [$response, $statements] = withStatements(fn () => $attack->call($this, $view));

        $response->assertNotFound()->assertExactJson(['message' => trans('agentic-actions::http.not_found')]);

        expect(readingPosts($statements))->toBe([]);
    })->with([
        'another member of the team' => [function (AgenticView $view) {
            $member = User::factory()->create();
            $this->acme->users()->attach($member);

            return $this->actingAs($member)->postJson("/teams/acme/actions/_views/{$view->id}");
        }],
        'the person, through another team, after leaving this one' => [function (AgenticView $view) {
            $this->user->teams()->detach($this->acme->id);

            return $this->actingAs($this->user)->postJson("/teams/other/actions/_views/{$view->id}");
        }],
        'the person, through a group that does not serve the dataset' => [fn (AgenticView $view) => $this->actingAs($this->user)->postJson("/plain/acme/actions/_views/{$view->id}")],
        'the person, once the conversation is deleted' => [function (AgenticView $view) {
            DB::table('agent_conversations')->where('id', $view->conversation_id)->delete();

            return $this->actingAs($this->user)->postJson("/teams/acme/actions/_views/{$view->id}");
        }],
        'a team that does not exist' => [fn (AgenticView $view) => $this->actingAs($this->user)->postJson("/teams/no-such-team/actions/_views/{$view->id}")],
        'a form post naming a team that does not exist' => [fn (AgenticView $view) => $this->actingAs($this->user)->post("/teams/no-such-team/actions/_views/{$view->id}")],
        'a form post naming a team the person is not in' => [function (AgenticView $view) {
            Team::factory()->create(['slug' => 'foreign']);

            return $this->actingAs($this->user)->post("/teams/foreign/actions/_views/{$view->id}");
        }],
        'the person\'s own token, with every ability' => [function (AgenticView $view) {
            $token = $this->user->createToken('client')->plainTextToken;
            $this->app['auth']->forgetGuards();

            return $this->withToken($token)->postJson("/api/teams/acme/actions/_views/{$view->id}");
        }],
    ]);

    it('refuses kept input the rules refuse now with 422, before any query reads posts', function (array $input, ?int $maxRows = null) {
        $view = ($this->shown)('posts', ['measures' => ['posts'], 'by' => ['status']]);
        $view->forceFill(['input' => $input])->save();

        if ($maxRows !== null) {
            config(['agentic-actions.views.max_rows' => $maxRows]);
        }

        [$response, $statements] = withStatements(fn () => $this->actingAs($this->user)->postJson("/teams/acme/actions/_views/{$view->id}"));

        $response->assertUnprocessable()->assertExactJson(['message' => trans('agentic-actions::http.invalid')]);

        expect(readingPosts($statements))->toBe([]);
    })->with([
        'a column as a dimension' => [['measures' => ['posts'], 'by' => ['team) from users --']]],
        'an expression as the sort' => [['measures' => ['posts'], 'by' => ['status'], 'sort' => 'count(*)']],
        'a first day before 1900' => [['measures' => ['posts'], 'since' => '1800-01-01', 'until' => '2026-09-30']],
        'more days than a lowered views.max_rows' => [['measures' => ['posts'], 'grain' => 'day', 'since' => '2026-09-01', 'until' => '2026-09-30'], 10],
    ]);

    it('runs the kept input, whatever view or other keys the request carries', function () {
        $view = ($this->shown)('posts-by-status', ['status' => 'Published', 'view' => 'mine']);

        $this->actingAs($this->user)
            ->postJson("/teams/acme/actions/_views/{$view->id}?view=all&status=draft", ['view' => 'all', 'status' => 'draft'])
            ->assertOk()
            ->assertJsonPath('table.rows', [['title' => 'Launch notes', 'status' => 'published']]);

        expect(PostsByStatus::$runs)->toHaveCount(2)
            ->and(PostsByStatus::$runs[1])->toBe(['input' => ['status' => 'published', 'view' => 'mine'], 'fixed' => [], 'ticket' => false]);
    });
});

describe('a reload', function () {
    beforeEach(function () {
        $this->skipUnlessAi();
        $this->useTeamTenancy();

        config([
            'agentic-actions.discovery.paths' => [],
            'agentic-actions.discovery.classes' => [PostStats::class, PostsByStatus::class],
        ]);

        $this->refreshActions();
        PostsByStatus::reset();

        $this->user = User::factory()->create();
        $this->acme = Team::factory()->create(['slug' => 'acme']);
        $this->user->teams()->attach($this->acme);
        Post::factory()->for($this->user)->forTeam($this->acme)->create(['title' => 'Launch notes', 'status' => 'canary-status']);

        // A turn in Acme that showed the author's posts with that status.
        (new OneStepGateway([new ToolCall('call_1', 'posts-by-status', ['status' => 'canary-status'])], 'Replied.'))->fake(ViewsAgent::class);
        Parts::body((new ViewsAgent($this->user, $this->acme))->forUser($this->user)->stream('Show me.'));
        ignore_user_abort(false);

        // The page's reload, through the agent as it stands now.
        $this->reload = fn (): array => Transcript::forUseChat(
            (string) DB::table('agent_conversations')->value('id'),
            $this->user,
            agent: (new ViewsAgent($this->user, $this->acme))->continueLastConversation($this->user),
        );
    });

    it('gives back no table of an action the person has lost, and no cell of it', function (Closure $lose) {
        expect(json_encode(($this->reload)(), JSON_THROW_ON_ERROR))->toContain('canary-status');

        $lose->call($this);
        $reloaded = ($this->reload)();

        expect($reloaded[1]['parts'])->toBe([['type' => 'text', 'text' => 'Replied.']])
            ->and(json_encode($reloaded, JSON_THROW_ON_ERROR))->not->toContain('canary-status');
    })->with([
        'shouldRegister() no longer offers it to the person' => [fn () => PostsByStatus::$registered = false],
        'the person left the team' => [fn () => $this->user->teams()->detach($this->acme->id)],
    ]);

    it('gives back no cell of a column the app removed', function () {
        PostsByStatus::$titleOnly = true;

        $reloaded = ($this->reload)();

        expect(tablesAmong($reloaded[1]['parts'])[0]['data']['table']['rows'])->toBe([['title' => 'Launch notes']])
            ->and(json_encode($reloaded, JSON_THROW_ON_ERROR))->not->toContain('canary-status');
    });
});

describe('the copilot', function () {
    beforeEach(function () {
        $this->skipUnlessAi();

        config([
            'agentic-actions.discovery.paths' => [],
            'agentic-actions.discovery.classes' => [PostStats::class, PostsByStatus::class],
        ]);

        $this->refreshActions();
        PostsByStatus::reset();
        $this->mountRoutes(fn () => Route::middleware(['web', 'auth'])->group(fn () => Actions::routes()));

        $this->user = User::factory()->create(['name' => 'CANARY-AUTHOR', 'email' => 'canary-author@example.com']);
        Post::factory()->for($this->user)->create(['title' => 'Launch notes', 'body' => 'one two three', 'status' => 'published']);

        // What the model read from each tool, in order.
        $this->heard = [];

        Event::listen(ToolInvoked::class, function (ToolInvoked $event): void {
            $this->heard[] = (string) $event->result;
        });

        // A streamed turn of the author's conversation, new unless an agent that continues one is given.
        $this->turn = function (OneStepGateway $gateway, ?ViewsAgent $agent = null): array {
            $gateway->fake(ViewsAgent::class);

            return Parts::of(Parts::body(($agent ?? (new ViewsAgent($this->user))->forUser($this->user))->stream('Show me.')));
        };

        // The page's reload of that conversation.
        $this->reload = fn (): array => Transcript::forUseChat(
            (string) DB::table('agent_conversations')->value('id'),
            $this->user,
            agent: (new ViewsAgent($this->user))->continueLastConversation($this->user),
        );
    });

    it('keeps a record in a text column out of the part, the model\'s copy, the kept table and the reload', function () {
        $parts = ($this->turn)(new OneStepGateway([new ToolCall('call_1', 'post-stats', [])], 'Replied.'));

        $doors = [
            'the part' => json_encode(tablesAmong($parts), JSON_THROW_ON_ERROR),
            'the model\'s copy' => implode("\n", $this->heard),
            'the kept table' => json_encode(AgenticView::query()->sole()->table, JSON_THROW_ON_ERROR),
            'the reload' => json_encode(tablesAmong(($this->reload)()[1]['parts']), JSON_THROW_ON_ERROR),
        ];

        foreach ($doors as $door => $text) {
            expect(str_contains($text, 'Launch notes'))->toBeTrue("{$door} shows the table")
                ->and(str_contains($text, 'CANARY-AUTHOR'))->toBeFalse("{$door} holds the author's name")
                ->and(str_contains($text, 'canary-author@example.com'))->toBeFalse("{$door} holds the author's email");
        }
    });

    it('keeps neither table when a tool-call id comes again, so no reload or refresh shows a table in its place', function (Closure $turns) {
        $shown = tablesAmong($turns->call($this));

        expect(array_column($shown, 'id'))->toBe(['view:call_1'])
            ->and(AgenticView::query()->count())->toBe(0)
            ->and(tablesAmong(array_merge(...array_column(($this->reload)(), 'parts'))))->toBe([]);

        $this->actingAs($this->user)->postJson("/actions/_views/{$shown[0]['data']['ref']}")->assertNotFound();
    })->with([
        'in the same turn' => [fn (): array => ($this->turn)(new OneStepGateway([
            new ToolCall('call_1', 'post-stats', []),
            new ToolCall('call_1', 'posts-by-status', ['status' => 'published']),
        ], 'Replied.'))],
        'in a later turn' => [function (): array {
            $first = ($this->turn)(new OneStepGateway([new ToolCall('call_1', 'post-stats', [])], 'Replied.'));

            ($this->turn)(
                new OneStepGateway([new ToolCall('call_1', 'posts-by-status', ['status' => 'published'])], 'Replied again.'),
                (new ViewsAgent($this->user))->continueLastConversation($this->user),
            );

            return $first;
        }],
    ]);

    it('shows at most ten tables in a turn, however the model spreads its calls over the steps', function () {
        $calls = array_map(fn (int $n): ToolCall => new ToolCall("call_{$n}", 'post-stats', []), range(1, 16));

        $parts = ($this->turn)(new ManyStepsGateway(array_chunk($calls, 8), 'Replied.'));

        expect(array_column(tablesAmong($parts), 'id'))->toBe(array_map(fn (int $n): string => "view:call_{$n}", range(1, 10)))
            ->and(array_column(Parts::rows($parts), 'status'))->toBe(array_fill(0, 16, 'done'))
            ->and(AgenticView::query()->count())->toBe(10)
            ->and(array_slice($this->heard, 10))->each->toStartWith("Found.\n---\n");
    });
});
