<?php

namespace Tests\Feature\Http;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use AgenticActions\Exceptions\MisconfiguredExposure;
use AgenticActions\Facades\Actions;
use AgenticActions\Http\ActionRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ValidatedInput;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\ListNotes;
use Tests\Fixtures\Actions\Trace;
use Tests\Fixtures\Actions\TracedNote;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * Actions::routes(): which routes exist, how they are named, and that the class still decides on every call.
 */

beforeEach(function () {
    Trace::reset();
    TracedNote::reset();

    $this->user = User::factory()->create();
});

/**
 * The names of the routes Actions::routes() generated, in registration order.
 *
 * @return list<string>
 */
function generatedRouteNames(): array
{
    $names = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if ($route->getAction(ActionRequest::GENERATED) === true) {
            $names[] = (string) $route->getName();
        }
    }

    return $names;
}

/**
 * What a route is, as the router and route:cache see it.
 *
 * @return array<string, mixed>
 */
function routeShape(RoutingRoute $route): array
{
    return [
        'uri' => $route->uri(),
        'methods' => $route->methods(),
        'name' => $route->getName(),
        'middleware' => $route->gatherMiddleware(),
        'action' => $route->getAction(),
    ];
}

it('registers one POST route per web-exposed action, named and prefixed from the group', function () {
    $this->mountRoutes(fn () => Route::middleware('auth')->group(fn () => Actions::routes()));

    $route = Route::getRoutes()->getByName('actions.create-note');

    expect($route)->toBeInstanceOf(RoutingRoute::class)
        ->and($route->uri())->toBe('actions/create-note')
        ->and($route->methods())->toBe(['POST'])
        ->and($route->getControllerClass())->toBe(CreateNote::class)
        ->and($route->gatherMiddleware())->toBe(['auth', HandlePrecognitiveRequests::class])
        ->and($route->getAction(ActionRequest::GENERATED))->toBeTrue()
        ->and(generatedRouteNames())->toBe([
            'actions.crashing-note',
            'actions.create-note',
            'actions.delete-note',
            'actions.hidden-note',
            'actions.keyed-note',
            'actions.late-authorize',
            'actions.list-notes',
            'actions.publish-note',
            'actions.refusing-note',
            'actions.team-note',
        ]);

    $this->actingAs($this->user)
        ->postJson('/actions/create-note', ['title' => 'Hi', 'body' => 'x'])
        ->assertOk()
        ->assertExactJson(['id' => Post::query()->value('id'), 'title' => 'Hi']);
});

it('reads the path and the name prefix from config at call time', function () {
    config(['agentic-actions.routes.path' => 'do', 'agentic-actions.routes.name' => 'do.']);

    $this->mountRoutes(fn () => Route::middleware('auth')->group(fn () => Actions::routes()));

    expect(Route::getRoutes()->getByName('do.create-note')?->uri())->toBe('do/create-note');
});

it('mounts the same actions in a second group, and neither mount counts as a twin', function () {
    $this->mountRoutes(function (): void {
        Route::middleware('auth')->group(fn () => Actions::routes());
        Route::middleware('auth:sanctum')->prefix('api')->name('api.')->group(fn () => Actions::routes());
    });

    expect(Route::getRoutes()->getByName('actions.create-note')?->uri())->toBe('actions/create-note')
        ->and(Route::getRoutes()->getByName('api.actions.create-note')?->uri())->toBe('api/actions/create-note')
        ->and(Route::getRoutes()->getByName('api.actions.create-note')?->gatherMiddleware())->toBe(['auth:sanctum', HandlePrecognitiveRequests::class]);

    $token = $this->user->createToken('client')->plainTextToken;

    $this->withToken($token)->postJson('/api/actions/create-note', ['title' => 'Api', 'body' => 'x'])->assertOk();
    $this->actingAs($this->user, 'web')->postJson('/actions/create-note', ['title' => 'Web', 'body' => 'x'])->assertOk();

    expect(Post::query()->orderBy('id')->pluck('title')->all())->toBe(['Api', 'Web']);
});

it('refuses an action a hand-written route registered earlier already serves', function () {
    expect(fn () => $this->mountRoutes(function (): void {
        Route::post('notes', CreateNote::class)->name('notes.store');
        Route::middleware('auth')->group(fn () => Actions::routes());
    }))->toThrow(
        MisconfiguredExposure::class,
        CreateNote::class.': http: the hand-written route [POST notes] already serves this class, so Actions::routes() would add a twin. Remove that route, or leave web out of #[Expose].',
    );
});

it('leaves a hand-written route registered later to actions:check', function () {
    $this->mountRoutes(function (): void {
        Route::middleware('auth')->group(fn () => Actions::routes());
        Route::post('notes', CreateNote::class)->name('notes.store');
    });

    expect(Route::getRoutes()->getByName('actions.create-note'))->not->toBeNull()
        ->and(Route::getRoutes()->getByName('notes.store'))->not->toBeNull();
});

it('registers only tenant-scoped actions with tenant: true, and only the others with tenant: false', function () {
    config(['agentic-actions.discovery.classes' => [UnscopedNote::class]]);
    $this->useTeamTenancy();

    $this->mountRoutes(function (): void {
        Route::middleware('auth')->prefix('teams/{team}')->name('teams.')->group(fn () => Actions::routes(tenant: true));
        Route::middleware('auth')->group(fn () => Actions::routes(tenant: false));
    });

    $names = generatedRouteNames();

    expect($names)->toContain('teams.actions.team-note', 'teams.actions.create-note', 'actions.unscoped-note')
        ->and($names)->not->toContain('teams.actions.unscoped-note')
        ->and($names)->not->toContain('actions.team-note')
        ->and($names)->not->toContain('actions.create-note');

    $team = Team::factory()->create(['slug' => 'acme']);
    $team->users()->attach($this->user);

    $this->actingAs($this->user)->postJson('/teams/acme/actions/team-note', ['title' => 'In the team'])->assertOk();
    $this->actingAs($this->user)->postJson('/actions/unscoped-note')->assertOk()->assertExactJson(['tenant' => false]);

    expect(Post::query()->value('team_id'))->toBe($team->getKey());
});

it('registers nothing while surfaces.web is off, and a route generated before answers not found', function () {
    $this->mountRoutes(fn () => Route::middleware('auth')->group(fn () => Actions::routes()));

    config(['agentic-actions.surfaces.web' => false]);

    $this->mountRoutes(fn () => Route::middleware('auth')->prefix('later')->name('later.')->group(fn () => Actions::routes()));

    expect(generatedRouteNames())->not->toContain('later.actions.create-note');

    $this->actingAs($this->user)
        ->postJson('/actions/create-note', ['title' => 'Hi', 'body' => 'x'])
        ->assertNotFound()
        ->assertExactJson(['message' => trans('agentic-actions::http.not_found')]);

    expect(Post::query()->count())->toBe(0);
});

it('refuses a generated route whose class no longer allows the web, running none of its code', function () {
    // The route a cache compiled before the class's #[Expose] changed would still hold (the shape is pinned below).
    $this->mountRoutes(fn () => Route::middleware('auth')->prefix('actions')->name('actions.')->group(
        fn () => $this->generatedRoute(TracedNote::class),
    ));

    $this->actingAs($this->user)
        ->postJson('/actions/traced-note', ['title' => 'Hi'])
        ->assertNotFound()
        ->assertExactJson(['message' => trans('agentic-actions::http.not_found')]);

    expect(Trace::$calls)->toBe([]);
});

it('keeps the generated key and the middleware through route:cache', function () {
    $this->mountRoutes(fn () => Route::middleware('auth')->group(fn () => Actions::routes()));

    // Exactly what route:cache writes and what a cached app loads: the compiled collection, exported and required.
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $route->prepareForSerialization();
    }

    $cache = sys_get_temp_dir().'/agentic-actions-routes-cache-'.getmypid().'.php';

    File::put($cache, '<?php app(\'router\')->setCompiledRoutes('.var_export(Route::getRoutes()->compile(), true).');');

    try {
        require $cache;
    } finally {
        File::delete($cache);
    }

    $route = Route::getRoutes()->getByName('actions.list-notes');

    expect(Route::getRoutes())->not->toBeInstanceOf(RouteCollection::class)
        ->and($route?->getAction(ActionRequest::GENERATED))->toBeTrue()
        ->and($route?->gatherMiddleware())->toBe(['auth', HandlePrecognitiveRequests::class]);

    Post::factory()->for($this->user)->create(['title' => 'Cached']);

    $this->actingAs($this->user)
        ->postJson('/actions/list-notes')
        ->assertOk()
        ->assertJsonPath('posts.0.title', 'Cached');

    $this->actingAs($this->user)
        ->postJson('/actions/create-note', ['title' => 'Hi'], ['Precognition' => 'true', 'Precognition-Validate-Only' => 'title'])
        ->assertNoContent()
        ->assertHeader('Precognition-Success', 'true');

    config(['agentic-actions.surfaces.web' => false]);

    $this->actingAs($this->user)->postJson('/actions/list-notes')->assertNotFound();
});

it('matches TestCase::generatedRoute(), which the earlier waves used in its place', function () {
    $this->mountRoutes(fn () => Route::middleware('auth')->group(
        fn () => Route::prefix('actions')->name('actions.')->group(fn () => $this->generatedRoute(ListNotes::class)),
    ));

    $helper = routeShape(Route::getRoutes()->getByName('actions.list-notes'));

    Route::setRoutes(new RouteCollection);

    $this->mountRoutes(fn () => Route::middleware('auth')->group(fn () => Actions::routes()));

    expect(routeShape(Route::getRoutes()->getByName('actions.list-notes')))->toBe($helper);
});

/**
 * Not tenant-scoped even once a tenant model is set.
 */
#[\AgenticActions\Attributes\Expose(web: true)]
final class UnscopedNote extends Action
{
    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    /**
     * Whether a tenant reached it.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['tenant' => $schema->boolean()->required()];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Report whether a tenant reached it.
     *
     * @return array{tenant: bool}
     */
    public function handle(ActionContext $context, ValidatedInput $input): array
    {
        return ['tenant' => $context->tenant !== null];
    }
}
