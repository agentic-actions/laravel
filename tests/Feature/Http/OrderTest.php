<?php

namespace Tests\Feature\Http;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use AgenticActions\Facades\Actions;
use AgenticActions\MissingContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ValidatedInput;
use Tests\Fixtures\Actions\TeamNote;
use Tests\Fixtures\Actions\Trace;
use Tests\Fixtures\Actions\TracedNote;
use Tests\Fixtures\Authorize\TwoStepAuthorize;
use Tests\Fixtures\Authorize\TwoStepReview;
use Tests\Fixtures\Misconfigured\NoAuthorize;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * The order the HTTP bridge runs the pipeline in, through the real kernel, Precognition and the responder.
 */

beforeEach(function () {
    Trace::reset();
    TracedNote::reset();
    TwoStepReview::$mayReview = true;

    $this->user = User::factory()->create();
});

it('answers an unexposed caller with 404 before the action reads any input', function () {
    $this->mountRoutes(fn () => Route::middleware('auth')->group(fn () => Actions::routes()));

    $this->actingAs($this->user)
        ->postJson('/actions/hidden-note', ['title' => 'Hi'])
        ->assertNotFound()
        ->assertExactJson(['message' => trans('agentic-actions::http.not_found')]);

    expect(Trace::$calls)->toBe(['shouldRegister']);
});

it('answers an input-free authorize() with 403 before a 422, and before Precognition', function () {
    TracedNote::$authorizes = false;

    $this->mountRoutes(function (): void {
        Route::post('traced', TracedNote::class);
        Route::post('traced-precognitive', TracedNote::class)->middleware(HandlePrecognitiveRequests::class);
    });

    $this->actingAs($this->user)
        ->postJson('/traced', ['title' => str_repeat('x', 30)])
        ->assertForbidden()
        ->assertExactJson(['message' => trans('agentic-actions::http.denied')]);

    $this->actingAs($this->user)
        ->postJson('/traced-precognitive', ['title' => str_repeat('x', 30)], ['Precognition' => 'true'])
        ->assertForbidden();

    expect(Trace::$calls)->toBe(['shouldRegister', 'authorize', 'shouldRegister', 'authorize']);
});

it('answers an input-taking authorize() with 403 after validation, for another user\'s row', function () {
    $foreign = Post::factory()->create();
    $own = Post::factory()->for($this->user)->create();

    $this->mountRoutes(fn () => Route::middleware('auth')->group(fn () => Actions::routes()));

    $this->actingAs($this->user)->postJson('/actions/late-authorize', ['post_id' => 'x'])->assertUnprocessable();

    expect(Trace::$calls)->toBe(['rules', 'prepareForValidation']);

    Trace::reset();

    $this->actingAs($this->user)
        ->postJson('/actions/late-authorize', ['post_id' => $foreign->getKey()])
        ->assertForbidden()
        ->assertExactJson(['message' => trans('agentic-actions::http.denied')]);

    expect(Trace::$calls)->toBe(['rules', 'prepareForValidation', 'authorize'])
        ->and($foreign->fresh()?->status)->toBe('draft');

    $this->actingAs($this->user)->postJson('/actions/late-authorize', ['post_id' => $own->getKey()])->assertOk();

    expect($own->fresh()?->status)->toBe('reviewed');
});

it('answers an authorize() whose input may be null with 403 before a 422, and before Precognition, when its first check refuses', function () {
    TwoStepReview::$mayReview = false;

    $this->mountRoutes(function (): void {
        Route::post('two-step', TwoStepAuthorize::class);
        Route::post('two-step-precognitive', TwoStepAuthorize::class)->middleware(HandlePrecognitiveRequests::class);
    });

    $this->actingAs($this->user)
        ->postJson('/two-step', ['post_id' => 'x'])
        ->assertForbidden()
        ->assertExactJson(['message' => trans('agentic-actions::http.denied')]);

    $this->actingAs($this->user)
        ->postJson('/two-step-precognitive', ['post_id' => 'x'], ['Precognition' => 'true'])
        ->assertForbidden();

    expect(Trace::$calls)->toBe(['authorize:before', 'authorize:before']);
});

it('answers an authorize() whose input may be null with 403 after validation when its second check refuses another user\'s row', function () {
    $foreign = Post::factory()->create();

    $this->mountRoutes(fn () => Route::post('two-step', TwoStepAuthorize::class));

    $this->actingAs($this->user)
        ->postJson('/two-step', ['post_id' => $foreign->getKey()])
        ->assertForbidden()
        ->assertExactJson(['message' => trans('agentic-actions::http.denied')]);

    expect(Trace::$calls)->toBe(['authorize:before', 'rules', 'prepareForValidation', 'authorize:after'])
        ->and($foreign->fresh()?->status)->toBe('draft');
});

it('never lets the body override a route parameter', function () {
    $this->mountRoutes(fn () => Route::middleware('auth')->prefix('drafts/{title}')->name('drafts.')->group(fn () => Actions::routes()));

    $this->actingAs($this->user)
        ->postJson('/drafts/From-the-route/actions/create-note', ['title' => 'From the body', 'body' => 'x'])
        ->assertOk()
        ->assertJsonPath('title', 'From-the-route');

    expect(Post::query()->value('title'))->toBe('From-the-route');
});

it('never lets the body supply a route parameter a middleware forgot', function () {
    $this->mountRoutes(fn () => Route::middleware(['auth', ForgetsTitle::class])->prefix('drafts/{title}')->name('drafts.')->group(fn () => Actions::routes()));

    $this->actingAs($this->user)
        ->postJson('/drafts/From-the-route/actions/create-note', ['title' => 'From the body', 'body' => 'x'])
        ->assertOk()
        ->assertJsonPath('title', 'From-the-route');
});

it('injects ActionContext and ValidatedInput by type beside a container service', function () {
    config(['app.name' => 'Notes']);

    $this->mountRoutes(fn () => Route::post('injecting', InjectingNote::class));

    $this->actingAs($this->user)
        ->postJson('/injecting', ['title' => 'Hi'])
        ->assertOk()
        ->assertExactJson(['title' => 'Hi', 'app' => 'Notes', 'surface' => 'http']);
});

// The bridge admits a call through Runner::admit(), not run(), so RunnerTest's case never reaches this door.
it('denies a class with no authorize()', function () {
    $this->mountRoutes(fn () => Route::middleware('auth')->post('no-authorize', NoAuthorize::class));

    $this->actingAs($this->user)
        ->postJson('/no-authorize')
        ->assertForbidden()
        ->assertExactJson(['message' => trans('agentic-actions::http.denied')]);
});

it('reads rules() before prepareForValidation(), both after an input-free authorize()', function () {
    $this->mountRoutes(fn () => Route::post('traced', TracedNote::class));

    $this->actingAs($this->user)->postJson('/traced', ['title' => str_repeat('x', 30)])->assertUnprocessable();

    expect(Trace::$calls)->toBe(['shouldRegister', 'authorize', 'rules', 'prepareForValidation']);

    Trace::reset();

    $this->actingAs($this->user)->postJson('/traced', ['title' => 'Hi'])->assertOk()->assertExactJson(['title' => 'Hi']);

    expect(Trace::$calls)->toBe(['shouldRegister', 'authorize', 'rules', 'prepareForValidation', 'handle', 'modelReply']);
});

it('runs every hook of one call on one action instance, as the in-process door does', function () {
    $this->mountRoutes(fn () => Route::post('traced', TracedNote::class));

    $this->actingAs($this->user)->postJson('/traced', ['title' => 'Hi'])->assertOk();

    // The router's controller, and the one instance every pipeline hook shares.
    expect(TracedNote::$instances)->toBe(2);
});

it('rethrows a MissingContext raised while admitting the call', function () {
    $this->useTeamTenancy();
    $this->withoutExceptionHandling();

    $this->mountRoutes(fn () => Route::post('team-note', TeamNote::class));

    expect(fn () => $this->actingAs($this->user)->postJson('/team-note', ['title' => 'Hi']))->toThrow(MissingContext::class);
});

it('answers Precognition with 204 on a generated route, filters by Precognition-Validate-Only, and never runs handle()', function () {
    $this->mountRoutes(fn () => Route::middleware('auth')->group(fn () => Actions::routes()));

    $this->actingAs($this->user)
        ->postJson('/actions/create-note', ['title' => 'Hi'], ['Precognition' => 'true', 'Precognition-Validate-Only' => 'title'])
        ->assertNoContent()
        ->assertHeader('Precognition-Success', 'true');

    $this->actingAs($this->user)
        ->postJson('/actions/create-note', ['title' => 'Hi'], ['Precognition' => 'true', 'Precognition-Validate-Only' => 'title,body'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['body'])
        ->assertJsonMissingValidationErrors(['title']);

    $this->actingAs($this->user)
        ->postJson('/actions/create-note', ['title' => 'Hi', 'body' => 'x'], ['Precognition' => 'true'])
        ->assertNoContent()
        ->assertHeader('Precognition-Success', 'true');

    expect(Post::query()->count())->toBe(0);
});

it('answers Precognition with 204 on a hand-written route without the middleware, and never runs handle()', function () {
    $this->mountRoutes(fn () => Route::post('traced', TracedNote::class));

    $this->actingAs($this->user)
        ->postJson('/traced', ['title' => 'Hi'], ['Precognition' => 'true'])
        ->assertNoContent()
        ->assertHeader('Precognition-Success', 'true');

    $this->actingAs($this->user)
        ->postJson('/traced', ['title' => str_repeat('x', 30)], ['Precognition' => 'true'])
        ->assertUnprocessable();

    expect(Trace::$calls)->not->toContain('handle');
});

it('requires a user on a generated route: 401 for a JSON guest, the login redirect for a visit', function () {
    $this->mountRoutes(function (): void {
        Route::get('login', fn () => 'Sign in')->name('login');
        Route::group([], fn () => Actions::routes());
    });

    $this->postJson('/actions/create-note', ['title' => 'Hi', 'body' => 'x'])
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);

    $this->post('/actions/create-note', ['title' => 'Hi', 'body' => 'x'])->assertRedirect('/login');

    expect(Post::query()->count())->toBe(0);
});

it('lets the group\'s auth middleware answer a guest first', function () {
    app(ExceptionHandler::class)->renderable(
        fn (AuthenticationException $exception) => response()->json(['guards' => $exception->guards()], 401),
    );

    $this->mountRoutes(function (): void {
        Route::middleware('auth:web')->prefix('guarded')->name('guarded.')->group(fn () => Actions::routes());
        Route::prefix('open')->name('open.')->group(fn () => Actions::routes());
    });

    $this->postJson('/guarded/actions/create-note')->assertUnauthorized()->assertExactJson(['guards' => ['web']]);
    $this->postJson('/open/actions/create-note')->assertUnauthorized()->assertExactJson(['guards' => []]);
});

/**
 * Asks the container for a service beside the context and the input, in an order of its own.
 */
final class InjectingNote extends Action
{
    protected ?Effect $effect = Effect::Write;

    /**
     * The note's title.
     */
    public function schema(JsonSchema $schema): array
    {
        return ['title' => $schema->string()->required()];
    }

    /**
     * What the call saw.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'app' => $schema->string()->required(),
            'surface' => $schema->string()->required(),
        ];
    }

    /**
     * Allowed, reading config from the container.
     */
    public function authorize(Repository $config, ActionContext $context): bool
    {
        return $context->actor !== null && $config->get('app.name') === 'Notes';
    }

    /**
     * Report what was injected.
     *
     * @return array<string, string>
     */
    public function handle(Repository $config, ValidatedInput $input, ActionContext $context): array
    {
        return [
            'title' => $input->string('title')->toString(),
            'app' => (string) $config->get('app.name'),
            'surface' => $context->surface->value,
        ];
    }
}

/**
 * Forgets the title route parameter once read, as a tenancy or locale middleware forgets its segment.
 */
final class ForgetsTitle
{
    /**
     * Forget the parameter, then go on.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $request->route()?->forgetParameter('title');

        return $next($request);
    }
}
