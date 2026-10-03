<?php

use AgenticActions\ActionContext;
use AgenticActions\MissingContext;
use AgenticActions\Refusal;
use AgenticActions\Surface;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/**
 * What a route closure reports about the context it built.
 *
 * @return array<string, mixed>
 */
function describeContext(ActionContext $context): array
{
    return [
        'surface' => $context->surface->value,
        'guard' => $context->guard,
        'actor' => $context->actor?->getAuthIdentifier(),
        'tenant' => $context->tenant?->getKey(),
        'tenant_marker' => $context->tenant?->getAttribute('marker'),
        'fixed' => $context->fixed,
        'key' => $context->idempotencyKey,
        'locale' => $context->locale,
    ];
}

afterEach(fn () => Relation::morphMap([], false));

it('records the guard that authenticated the request', function () {
    $user = User::factory()->create();

    $this->mountRoutes(fn () => Route::middleware('auth:sanctum')->post('probe', fn (Request $request) => describeContext(ActionContext::fromRequest($request))));

    $this->withToken($user->createToken('test')->plainTextToken)
        ->postJson('/probe')
        ->assertOk()
        ->assertJson(['surface' => 'http', 'guard' => 'sanctum', 'actor' => $user->getKey()]);
});

it('reads a trimmed Idempotency-Key header of 1 to 255 characters', function (string $header, ?string $expected) {
    $this->mountRoutes(fn () => Route::post('probe', fn (Request $request) => describeContext(ActionContext::fromRequest($request))));

    $this->postJson('/probe', [], ['Idempotency-Key' => $header])->assertJson(['key' => $expected]);
})->with([
    'trimmed' => ['  abc  ', 'abc'],
    'blank' => ['   ', null],
    'too long' => [str_repeat('k', 256), null],
    'longest' => [str_repeat('k', 255), str_repeat('k', 255)],
]);

it('resolves a slug tenant parameter by route key', function () {
    $this->useTeamTenancy();
    $team = Team::factory()->create(['slug' => 'acme']);

    $this->mountRoutes(fn () => Route::post('teams/{team}/probe/{post}', fn (Request $request) => describeContext(ActionContext::fromRequest($request))));

    $this->postJson('/teams/acme/probe/7')
        ->assertOk()
        ->assertJson(['tenant' => $team->getKey()])
        ->assertJsonPath('fixed', ['post' => '7']);
});

it('uses a parameter already bound to the tenant model as is', function () {
    $this->useTeamTenancy();
    $team = Team::factory()->create(['slug' => 'acme']);

    $this->mountRoutes(function () use ($team): void {
        Route::bind('team', fn (string $value) => $team->setAttribute('marker', 'bound'));
        Route::middleware(SubstituteBindings::class)->post('teams/{team}/probe', fn (Request $request) => describeContext(ActionContext::fromRequest($request)));
    });

    $this->postJson('/teams/acme/probe')
        ->assertOk()
        ->assertJson(['tenant' => $team->getKey(), 'tenant_marker' => 'bound', 'fixed' => []]);
});

it('keeps a tenant route parameter as fixed input when no tenant model is set', function () {
    $this->mountRoutes(fn () => Route::post('teams/{tenant}/probe', fn (Request $request) => describeContext(ActionContext::fromRequest($request))));

    $this->postJson('/teams/acme/probe')
        ->assertOk()
        ->assertJson(['tenant' => null, 'fixed' => ['tenant' => 'acme']]);
});

it('gives no fixed input for a request without a bound route', function () {
    expect(ActionContext::fromRequest(Request::create('/nowhere', 'POST'))->fixed)->toBe([]);

    $request = Request::create('/posts/5', 'POST');
    $request->setRouteResolver(fn () => new RoutingRoute('POST', 'posts/{post}', fn () => null));

    expect(ActionContext::fromRequest($request)->fixed)->toBe([]);
});

it('has a private constructor', function () {
    expect((new ReflectionMethod(ActionContext::class, '__construct'))->isPrivate())->toBeTrue();
});

it('builds the named contexts', function () {
    config(['auth.defaults.guard' => 'web']);

    $http = ActionContext::http(null);
    $agent = ActionContext::agent(null, null, 'ar');
    $system = ActionContext::system();
    $console = ActionContext::console(null, null, 'en', 'key');

    expect($http->surface)->toBe(Surface::Http)
        ->and($http->actor)->toBeNull()
        ->and($http->guard)->toBe('web')
        ->and($agent->surface)->toBe(Surface::Agent)
        ->and($agent->locale)->toBe('ar')
        ->and($agent->guard)->toBe('web')
        ->and($system->surface)->toBe(Surface::System)
        ->and($system->actor)->toBeNull()
        ->and($system->guard)->toBeNull()
        ->and($console->surface)->toBe(Surface::Console)
        ->and($console->guard)->toBeNull()
        ->and($console->idempotencyKey)->toBe('key');
});

it('gives a morph-typed actor key', function () {
    $user = User::factory()->create();

    expect(ActionContext::http($user)->actorKey())->toBe(User::class.':'.$user->getKey());

    Relation::morphMap(['user' => User::class]);

    expect(ActionContext::http($user)->actorKey())->toBe('user:'.$user->getKey())
        ->and(ActionContext::http(new GenericUser(['id' => 5]))->actorKey())->toBe(GenericUser::class.':5')
        ->and(ActionContext::http(null)->actorKey())->toBe('guest')
        ->and(ActionContext::agent(null)->actorKey())->toBe('guest')
        ->and(ActionContext::system()->actorKey())->toBe('system');
});

it('namespaces the idempotency key by action, actor, tenant and raw key', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $team = Team::factory()->create();

    $key = fn (ActionContext $context): string => $context->requireIdempotencyKey();
    $base = ActionContext::http($first)->withIdempotencyKey('k-1')->forAction('create-note');

    expect($key($base))->toBe($key(ActionContext::http($first)->withIdempotencyKey('k-1')->forAction('create-note')))
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($key($base->forAction('other-note')))->not->toBe($key($base))
        ->and($key(ActionContext::http($second)->withIdempotencyKey('k-1')->forAction('create-note')))->not->toBe($key($base))
        ->and($key(ActionContext::http($first, $team)->withIdempotencyKey('k-1')->forAction('create-note')))->not->toBe($key($base))
        ->and($key($base->withIdempotencyKey('k-2')))->not->toBe($key($base))
        ->and($key(ActionContext::system()->withIdempotencyKey('k-1')->forAction('create-note')))->not->toBe($key($base));
});

it('refuses with 428 for a model-driven guest, even with a raw key', function () {
    try {
        ActionContext::agent(null)->withIdempotencyKey('k-1')->forAction('keyed-note')->requireIdempotencyKey();
        $this->fail('No refusal.');
    } catch (Refusal $refusal) {
        expect($refusal->statusCode())->toBe(428)
            ->and($refusal->key())->toBe('agentic-actions::model.idempotency_required');
    }

    expect(ActionContext::agent(User::factory()->create())->withIdempotencyKey('k-1')->forAction('keyed-note')->requireIdempotencyKey())
        ->toBeString();
});

it('finds a row through the tenant scope class', function () {
    $this->useTeamTenancy();

    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $own = Post::factory()->forTeam($team)->create();
    $foreign = Post::factory()->forTeam($other)->create();
    $context = ActionContext::http(User::factory()->create(), $team);

    expect($context->find(Post::class, $own->getKey())->is($own))->toBeTrue();

    expect(fn () => $context->find(Post::class, $foreign->getKey()))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $context->find(User::class, 1))->toThrow(LogicException::class)
        ->and(fn () => ActionContext::http(null)->find(Post::class, $own->getKey()))->toThrow(MissingContext::class);
});

it('finds any row with findOrFail() when no tenant model is set', function () {
    $post = Post::factory()->create();

    expect(ActionContext::http(null)->find(Post::class, $post->getKey())->is($post))->toBeTrue()
        ->and(fn () => ActionContext::http(null)->find(Post::class, 999))->toThrow(ModelNotFoundException::class);
});

it('reads the actor and tenant, or throws MissingContext', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $context = ActionContext::http($user, $team);

    expect($context->actor(User::class))->toBe($user)
        ->and($context->tenant(Team::class))->toBe($team)
        ->and(fn () => $context->tenant(User::class))->toThrow(MissingContext::class)
        ->and(fn () => ActionContext::http(null)->actor())->toThrow(MissingContext::class);
});

it('keeps the request id through every with*()', function () {
    $context = ActionContext::http(null);

    expect($context->withFixed(['a' => 1])->requestId)->toBe($context->requestId)
        ->and($context->withIdempotencyKey('k')->requestId)->toBe($context->requestId)
        ->and($context->withSurface(Surface::Agent)->requestId)->toBe($context->requestId)
        ->and($context->forAction('x')->requestId)->toBe($context->requestId)
        ->and(ActionContext::http(null)->requestId)->not->toBe($context->requestId)
        ->and($context->withFixed(['a' => 1])->withFixed(['b' => 2])->fixed)->toBe(['a' => 1, 'b' => 2]);
});
