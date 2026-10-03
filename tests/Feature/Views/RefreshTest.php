<?php

use AgenticActions\Exceptions\MisconfiguredExposure;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Facades\Actions;
use AgenticActions\Refusal;
use AgenticActions\Streaming\AgenticView;
use AgenticActions\Views\RefreshView;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Fixtures\Streaming\OneStepGateway;
use Tests\Fixtures\Streaming\Parts;
use Tests\Fixtures\Views\GuardedPosts;
use Tests\Fixtures\Views\Invalid\ViewsNamed;
use Tests\Fixtures\Views\PostsByDay;
use Tests\Fixtures\Views\PostsByStatus;
use Tests\Fixtures\Views\PostStats;
use Tests\Fixtures\Views\ViewsAgent;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * The refresh route gives the person a table was shown to its rows again, now, through the pipeline as on the web,
 * with the input and fixed keys it ran with. Everyone and everything else gets the same 404.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config([
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'agentic-actions.discovery.paths' => [],
        'agentic-actions.discovery.classes' => [PostStats::class, PostsByStatus::class, PostsByDay::class],
        'ai.conversations.generate_title' => false,
    ]);

    $this->refreshActions();
    Auth::shouldUse('web');
    PostsByStatus::reset();

    $this->user = User::factory()->create();
    Post::factory()->for($this->user)->create(['title' => 'Launch notes', 'status' => 'published']);

    // A turn of a new conversation that shows one table, and the table it kept.
    $this->shown = function (string $action, array $arguments, ?ViewsAgent $agent = null): AgenticView {
        (new OneStepGateway([new ToolCall('call_1', $action, $arguments)], 'Replied.'))->fake(ViewsAgent::class);

        Parts::body(($agent ?? new ViewsAgent($this->user))->forUser($this->user)->stream('Show me.'));
        ignore_user_abort(false);

        return AgenticView::query()->orderByDesc('id')->firstOrFail();
    };

    $this->notFound = ['message' => trans('agentic-actions::http.not_found')];
});

describe('without tenants', function () {
    beforeEach(function () {
        $this->mountRoutes(function () {
            Route::middleware(['web', 'auth'])->group(fn () => Actions::routes());
            Route::middleware('auth:sanctum')->prefix('api')->name('api.')->group(fn () => Actions::routes());
        });
    });

    it('gives the person fresh rows with their time, and keeps the snapshot as it was', function () {
        $view = ($this->shown)('posts-by-status', ['status' => 'published']);
        Post::factory()->for($this->user)->create(['title' => 'Roadmap', 'status' => 'published']);
        $this->travel(5)->minutes();

        $this->actingAs($this->user)->postJson("/actions/_views/{$view->id}")->assertOk()->assertExactJson([
            'table' => [
                'columns' => [['key' => 'title', 'label' => 'Title', 'type' => 'text'], ['key' => 'status', 'label' => 'Status', 'type' => 'text']],
                'rows' => [['title' => 'Launch notes', 'status' => 'published'], ['title' => 'Roadmap', 'status' => 'published']],
                'truncated' => false,
                'chart' => ['type' => 'none', 'y' => []],
                'caption' => null,
            ],
            'at' => now()->format(DATE_ATOM),
        ]);

        expect($view->fresh()?->table['rows'])->toBe([['title' => 'Launch notes', 'status' => 'published']]);
    });

    it('runs again with the input the call ran with, never the route\'s view', function () {
        $view = ($this->shown)('posts-by-status', ['status' => 'Published', 'view' => 'mine']);

        $this->actingAs($this->user)->postJson('/actions/_views/'.strtoupper($view->id))->assertOk();

        expect(PostsByStatus::$runs)->toHaveCount(2)
            ->and(PostsByStatus::$runs[1])->toBe(PostsByStatus::$runs[0])
            ->and(PostsByStatus::$runs[1])->toBe(['input' => ['status' => 'published', 'view' => 'mine'], 'fixed' => [], 'ticket' => false]);
    });

    it('never refreshes a call that ran on fixed input, which the refresh route cannot check again', function () {
        $view = ($this->shown)('posts-by-status', ['status' => 'published'], new ViewsAgent($this->user, fixed: ['source' => 'copilot']));

        expect(RefreshView::allowed(ClassExposure::of(PostsByStatus::class), $view->fixed))->toBeFalse();

        $this->actingAs($this->user)->postJson("/actions/_views/{$view->id}")->assertNotFound()->assertExactJson($this->notFound);

        expect(PostsByStatus::$runs)->toHaveCount(1);
    });

    it('never refreshes an action whose class declares its own middleware, which only its route applies', function () {
        expect(RefreshView::allowed(ClassExposure::of(PostsByStatus::class), []))->toBeTrue()
            ->and(RefreshView::allowed(ClassExposure::of(GuardedPosts::class), []))->toBeFalse();
    });

    it('answers 404, reported at most once an hour, while the tables are not migrated', function () {
        $view = ($this->shown)('posts-by-status', ['status' => 'published']);
        Exceptions::fake();
        Schema::connection((new AgenticView)->getConnectionName())->drop('agentic_views');

        foreach ([1, 2, 3] as $attempt) {
            $this->actingAs($this->user)->postJson("/actions/_views/{$view->id}")->assertNotFound()->assertExactJson($this->notFound);
        }

        Exceptions::assertReportedCount(1);
    });

    it('answers the same 404 to anyone but the person, and for anything but their table', function (Closure $change, ?string $path = null) {
        $view = ($this->shown)('posts-by-status', ['status' => 'published']);
        $runs = count(PostsByStatus::$runs);

        $request = $change->call($this, $view) ?? $this->actingAs($this->user);

        $request->postJson($path ?? "/actions/_views/{$view->id}")->assertNotFound()->assertExactJson($this->notFound);

        expect(PostsByStatus::$runs)->toHaveCount($runs);
    })->with([
        'another person' => [fn (AgenticView $view) => $this->actingAs(User::factory()->create())],
        'a table kept for another person in the actor\'s own conversation' => [function (AgenticView $view): null {
            $view->forceFill(['participant_id' => User::factory()->create()->id])->save();

            return null;
        }],
        'an unknown id' => [fn (AgenticView $view) => null, '/actions/_views/'.Str::ulid()],
        'an id that is no ULID' => [fn (AgenticView $view) => null, '/actions/_views/1%20or%201=1'],
        'a deleted conversation' => [function (AgenticView $view): null {
            DB::table('agent_conversations')->where('id', $view->conversation_id)->delete();

            return null;
        }],
        'a changed authorize()' => [function (): null {
            PostsByStatus::$allowed = false;

            return null;
        }],
    ]);

    it('answers 404 to a token, even the person\'s own', function () {
        $view = ($this->shown)('posts-by-status', ['status' => 'published']);
        $token = $this->user->createToken('client', ['actions:read'])->plainTextToken;

        $this->app['auth']->forgetGuards();

        $this->withToken($token)->postJson("/api/actions/_views/{$view->id}")->assertNotFound()->assertExactJson($this->notFound);
    });

    it('answers 404 for an action not open on the web, whose table carries no ref', function () {
        $view = ($this->shown)('posts-by-day', []);

        $this->actingAs($this->user)->postJson("/actions/_views/{$view->id}")->assertNotFound()->assertExactJson($this->notFound);
    });

    it('answers 422 with the fixed sentence when the rules no longer take the input', function () {
        $view = ($this->shown)('posts-by-status', ['status' => 'draft']);
        PostsByStatus::$strict = true;

        $this->actingAs($this->user)->postJson("/actions/_views/{$view->id}")->assertUnprocessable()->assertExactJson(['message' => trans('agentic-actions::http.invalid')]);
    });

    it('answers a refusal with its status and sentence', function () {
        $view = ($this->shown)('posts-by-status', ['status' => 'published']);
        PostsByStatus::$refusal = Refusal::make('posts.locked', 'The posts are locked for now.')->status(423);

        $this->actingAs($this->user)->postJson("/actions/_views/{$view->id}")->assertStatus(423)->assertExactJson(['message' => 'The posts are locked for now.']);
    });

    it('answers a failure with the fixed line, reported once', function () {
        $view = ($this->shown)('posts-by-status', ['status' => 'published']);
        Exceptions::fake();
        PostsByStatus::$crash = true;

        $this->actingAs($this->user)->postJson("/actions/_views/{$view->id}")->assertStatus(500)->assertExactJson(['message' => trans('agentic-actions::activity.failed')]);

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'The posts could not be read.');
    });
});

it('refuses an action that takes the name _views', function () {
    config(['agentic-actions.discovery.classes' => [ViewsNamed::class]]);
    $this->refreshActions();

    expect(fn () => Actions::routes())->toThrow(MisconfiguredExposure::class, ViewsNamed::class.': the name [_views] is the tables\' refresh route; set another $name.');
});

describe('with tenants', function () {
    beforeEach(function () {
        $this->useTeamTenancy();

        [$this->acme, $this->other] = [Team::factory()->create(['slug' => 'acme']), Team::factory()->create(['slug' => 'other'])];
        $this->user->teams()->attach([$this->acme->id, $this->other->id]);
        Post::query()->update(['team_id' => $this->acme->id]);

        $this->mountRoutes(function () {
            Route::middleware(['web', 'auth'])->prefix('teams/{team}')->name('teams.')->group(fn () => Actions::routes(tenant: true));
            Route::middleware(['web', 'auth'])->prefix('all/{team}')->name('all.')->group(fn () => Actions::routes());
        });
    });

    it('refreshes a table in the tenant it was shown in', function () {
        $view = ($this->shown)('posts-by-status', ['status' => 'published'], new ViewsAgent($this->user, $this->acme));

        $this->actingAs($this->user)->postJson("/teams/acme/actions/_views/{$view->id}")->assertOk()->assertJsonPath('table.rows', [['title' => 'Launch notes', 'status' => 'published']]);
    });

    it('answers 404 in another tenant, and after the person left the tenant', function () {
        $view = ($this->shown)('posts-by-status', ['status' => 'published'], new ViewsAgent($this->user, $this->acme));

        $this->actingAs($this->user)->postJson("/teams/other/actions/_views/{$view->id}")->assertNotFound()->assertExactJson($this->notFound);

        $this->user->teams()->detach($this->acme->id);

        $this->actingAs($this->user)->postJson("/teams/acme/actions/_views/{$view->id}")->assertNotFound()->assertExactJson($this->notFound);
    });

    it('answers 404 through a group that does not register the action', function () {
        $view = ($this->shown)('post-stats', [], new ViewsAgent($this->user, $this->acme));

        $this->actingAs($this->user)->postJson("/all/acme/actions/_views/{$view->id}")->assertOk();
        $this->actingAs($this->user)->postJson("/teams/acme/actions/_views/{$view->id}")->assertNotFound()->assertExactJson($this->notFound);
    });
});
