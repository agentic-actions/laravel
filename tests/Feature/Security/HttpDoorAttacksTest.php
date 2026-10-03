<?php

namespace Tests\Feature\Security;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use AgenticActions\Facades\Actions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ValidatedInput;
use Tests\Fixtures\Actions\ChildNote;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\DeleteNote;
use Tests\Fixtures\Actions\ListNotes;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * A hostile caller on a generated route: a guest, a forged cross-site post, a subclass that never repeated
 * #[Expose], a body that names a route parameter, and a token whose limits an action tries to step around.
 */

beforeEach(function () {
    BoardNote::$received = null;
    TeamKeyNote::$received = null;
    AccountNote::$called = false;

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    $this->user = User::factory()->create();
});

afterEach(function () {
    // Teardown runs commands that ask for confirmation in production.
    $this->app['env'] = 'testing';
});

it('answers a guest 401 before the tenant is looked up, for a known and an unknown team alike', function () {
    $this->useTeamTenancy();

    $this->mountRoutes(fn () => Route::prefix('teams/{team}')->name('teams.')->group(fn () => Actions::routes(tenant: true)));

    Team::factory()->create(['slug' => 'acme']);

    $known = $this->postJson('/teams/acme/actions/team-note', ['title' => 'Hi'])->assertUnauthorized();
    $unknown = $this->postJson('/teams/no-such-team/actions/team-note', ['title' => 'Hi'])->assertUnauthorized();

    expect($known->getContent())->toBe($unknown->getContent())
        ->and(Post::query()->count())->toBe(0);
});

it('keeps generated routes behind the web group\'s CSRF check, Precognition included', function () {
    $this->mountRoutes(fn () => Route::middleware(['web', 'auth'])->group(fn () => Actions::routes()));

    // The framework skips its CSRF check while unit tests run; production is where a forged post arrives.
    $this->app['env'] = 'production';

    $this->actingAs($this->user)->postJson('/actions/create-note', ['title' => 'Hi', 'body' => 'x'])->assertStatus(419);

    $this->actingAs($this->user)
        ->postJson('/actions/create-note', ['title' => 'Hi', 'body' => 'x'], ['Precognition' => 'true'])
        ->assertStatus(419);

    expect(Post::query()->count())->toBe(0);
});

it('refuses to run anything but a Read on a request the CSRF check reads as safe', function () {
    $this->mountRoutes(function (): void {
        Route::middleware(['web', 'auth'])->get('notes/create', CreateNote::class)->name('notes.create');
        Route::middleware(['web', 'auth'])->get('notes', ListNotes::class)->name('notes.index');
    });

    $this->actingAs($this->user)->get('/notes/create?title=Hi&body=x')->assertMethodNotAllowed();
    $this->actingAs($this->user)->call('HEAD', '/notes/create?title=Hi&body=x')->assertMethodNotAllowed();
    $this->actingAs($this->user)->getJson('/notes')->assertOk();

    expect(Post::query()->count())->toBe(0);
});

it('never generates a route for a subclass that did not repeat #[Expose], and refuses one a stale cache holds', function () {
    $this->mountRoutes(function (): void {
        Route::middleware('auth')->group(fn () => Actions::routes());
        Route::middleware('auth')->prefix('stale')->name('stale.')->group(fn () => $this->generatedRoute(ChildNote::class));
    });

    expect(Route::has('actions.create-note'))->toBeTrue()
        ->and(Route::has('actions.child-note'))->toBeFalse();

    $this->actingAs($this->user)->postJson('/stale/child-note', ['title' => 'Hi', 'body' => 'x'])->assertNotFound();

    expect(Post::query()->count())->toBe(0);
});

it('never lets a body supply a route parameter the action checks only in rules()', function () {
    config(['agentic-actions.discovery.classes' => [BoardNote::class]]);
    $this->refreshActions();

    $this->mountRoutes(fn () => Route::middleware('auth')->prefix('boards/{board}')->name('boards.')->group(fn () => Actions::routes()));

    $this->actingAs($this->user)
        ->postJson('/boards/mine/actions/board-note', ['title' => 'Hi', 'board' => 'theirs'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['board']);

    expect(BoardNote::$received)->toBeNull();
});

it('never lets a body supply the tenant\'s route parameter either', function () {
    $this->useTeamTenancy();
    config(['agentic-actions.discovery.classes' => [TeamKeyNote::class]]);
    $this->refreshActions();

    $this->mountRoutes(fn () => Route::middleware('auth')->prefix('teams/{team}')->name('teams.')->group(fn () => Actions::routes(tenant: true)));

    $acme = Team::factory()->create(['slug' => 'acme']);
    $acme->users()->attach($this->user);

    $this->actingAs($this->user)
        ->postJson('/teams/acme/actions/team-key-note', ['title' => 'Hi', 'team' => 'victim-corp'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['team']);

    expect(TeamKeyNote::$received)->toBeNull();
});

it('keeps a tenant-bound token off an account-level action mounted under its tenant\'s URL', function () {
    $this->useTeamTenancy();
    config(['agentic-actions.discovery.classes' => [AccountNote::class, TeamKeyNote::class]]);
    $this->refreshActions();

    // One group under the tenant prefix that serves every action, the default.
    $this->mountRoutes(fn () => Route::middleware(['api', 'auth:sanctum'])->prefix('api/teams/{team}')->name('api.teams.')->group(fn () => Actions::routes()));

    $acme = Team::factory()->create(['slug' => 'acme']);
    $acme->users()->attach($this->user);
    $bound = $this->user->createToken('acme', ['actions:write', 'tenant:'.$acme->id])->plainTextToken;

    $this->withToken($bound)->postJson('/api/teams/acme/actions/account-note', ['title' => 'Hi'])->assertNotFound();
    expect(AccountNote::$called)->toBeFalse();

    // The same token still reaches its tenant's own actions there.
    $this->app['auth']->forgetGuards();
    $this->withToken($bound)->postJson('/api/teams/acme/actions/team-key-note', ['title' => 'Hi'])->assertUnprocessable()->assertJsonValidationErrors(['team']);
});

it('keeps a token\'s limits on an action another action runs in-process', function () {
    config(['agentic-actions.discovery.classes' => [PurgingNote::class]]);
    $this->refreshActions();

    $this->mountRoutes(fn () => Route::middleware(['api', 'auth:sanctum'])->prefix('api')->name('api.')->group(fn () => Actions::routes()));

    $post = Post::factory()->for($this->user)->create();
    $write = $this->user->createToken('write', ['actions:write'])->plainTextToken;
    $all = $this->user->createToken('all')->plainTextToken;

    $this->withToken($write)
        ->postJson('/api/actions/purging-note', ['note' => $post->getKey()])
        ->assertNotFound()
        ->assertJsonPath('message', trans('agentic-actions::http.not_found'));

    expect(Post::query()->count())->toBe(1);

    $this->app['auth']->forgetGuards();

    $this->withToken($all)->postJson('/api/actions/purging-note', ['note' => $post->getKey()])->assertOk();

    expect(Post::query()->count())->toBe(0);
});

it('keeps a tenant-bound token inside its tenant, whatever else it grants', function (array $abilities, int $bound, int $unbound) {
    $this->useTeamTenancy();

    $this->mountRoutes(function (): void {
        Route::middleware(['api', 'auth:sanctum'])->prefix('api')->name('api.')->group(fn () => Actions::routes(tenant: false));
        Route::middleware(['api', 'auth:sanctum'])->prefix('api/teams/{team}')->name('api.teams.')->group(fn () => Actions::routes(tenant: true));
    });

    $team = Team::factory()->create(['slug' => 'acme']);
    $team->users()->attach($this->user);

    $token = $this->user->createToken('bound', array_map(fn (string $ability): string => str_replace('{team}', (string) $team->getKey(), $ability), $abilities))->plainTextToken;

    $this->withToken($token)->postJson('/api/teams/acme/actions/team-note', ['title' => 'Hi'])->assertStatus($bound);
    $this->withToken($token)->postJson('/api/actions/create-note', ['title' => 'Hi', 'body' => 'x'])->assertStatus($unbound);
})->with([
    'every ability, bound to the team' => [['*', 'tenant:{team}'], 200, 404],
    'a wildcard where a key belongs' => [['actions:write', 'tenant:*'], 404, 404],
]);

/**
 * A Write whose route parameter is checked only in rules().
 */
#[Expose(web: true)]
final class BoardNote extends Action
{
    /**
     * What handle() received.
     *
     * @var array<string, mixed>|null
     */
    public static ?array $received = null;

    protected ?Effect $effect = Effect::Write;

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
        ];
    }

    public function rules(ActionContext $context): array
    {
        return [
            'board' => ['required', 'string'],
        ];
    }

    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    public function handle(ActionContext $context, ValidatedInput $input): array
    {
        self::$received = $input->all();

        return [];
    }
}

/**
 * A Write that runs a Destructive action in-process, with the same context.
 */
#[Expose(web: true)]
final class PurgingNote extends Action
{
    protected ?Effect $effect = Effect::Write;

    public function schema(JsonSchema $schema): array
    {
        return [
            'note' => $schema->integer()->required(),
        ];
    }

    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        return DeleteNote::run(['note' => $input->integer('note')], $context);
    }
}

/**
 * A tenant-scoped Write that checks a key named like the tenant's route parameter in rules() only.
 */
#[Expose(web: true)]
final class TeamKeyNote extends Action
{
    /**
     * What handle() received.
     *
     * @var array<string, mixed>|null
     */
    public static ?array $received = null;

    protected ?Effect $effect = Effect::Write;

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
        ];
    }

    /**
     * A key named like the tenant's route parameter, checked only here.
     */
    public function rules(ActionContext $context): array
    {
        return [
            'team' => ['required', 'string'],
        ];
    }

    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    public function handle(ActionContext $context, ValidatedInput $input): array
    {
        self::$received = $input->all();

        return [];
    }
}

/**
 * An account-level Write: no tenant scope.
 */
#[Expose(web: true)]
final class AccountNote extends Action
{
    public static bool $called = false;

    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
        ];
    }

    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    public function handle(ActionContext $context, ValidatedInput $input): array
    {
        self::$called = true;

        return [];
    }
}
