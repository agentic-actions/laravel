<?php

namespace Tests\Feature\Http;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use AgenticActions\Facades\Actions;
use AgenticActions\Refusal;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ValidatedInput;
use Inertia\Support\SessionKey;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * What a browser visit receives: a flash and a 303 on success, and the app's own handler for every failure, so Blade's
 * @error and old() and Inertia's errors work as they do for any Laravel form.
 */

beforeEach(function () {
    config([
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'agentic-actions.discovery.classes' => [RedirectingNote::class, BaggedNote::class, AttributeBaggedNote::class, TitleRefusingNote::class],
    ]);

    $this->refreshActions();

    $this->user = User::factory()->create();

    $this->mountRoutes(function (): void {
        Route::middleware(['web', 'auth'])->group(function (): void {
            Actions::routes();

            Route::get('notes/new', fn () => Blade::render(
                '<p>@error(\'title\'){{ $message }}@enderror</p><p>@error(\'action\'){{ $message }}@enderror</p><input value="{{ old(\'title\') }}">',
            ));
        });
    });
});

it('flashes the output and redirects 303 back after a success', function () {
    $response = $this->actingAs($this->user)
        ->from('/notes/new')
        ->post('/actions/create-note', ['title' => 'Hi', 'body' => 'x'])
        ->assertStatus(303)
        ->assertRedirect('/notes/new')
        ->assertSessionHas('action', ['name' => 'create-note', 'output' => ['id' => Post::query()->value('id'), 'title' => 'Hi']]);

    expect($response->getSession()?->get(SessionKey::FLASH_DATA))->toBeNull();
});

it('redirects 303 to redirectTo() when the action names a place', function () {
    $this->actingAs($this->user)
        ->from('/notes/new')
        ->post('/actions/redirecting-note', ['title' => 'Hi'])
        ->assertStatus(303)
        ->assertRedirect('/notes/'.Post::query()->value('id'))
        ->assertSessionHas('action.name', 'redirecting-note');
});

it('flashes through Inertia::flash() on an Inertia visit', function () {
    $response = $this->actingAs($this->user)
        ->from('/notes/new')
        ->post('/actions/create-note', ['title' => 'Hi', 'body' => 'x'], ['X-Inertia' => 'true'])
        ->assertStatus(303)
        ->assertRedirect('/notes/new')
        ->assertSessionMissing('action');

    expect($response->getSession()?->get(SessionKey::FLASH_DATA))->toBe([
        'action' => ['name' => 'create-note', 'output' => ['id' => Post::query()->value('id'), 'title' => 'Hi']],
    ]);
});

it('redirects back with errors in the default bag and the old input, minus the app\'s dontFlash keys', function () {
    app(ExceptionHandler::class)->dontFlash('card_number');

    $this->actingAs($this->user)
        ->from('/notes/new')
        ->post('/actions/create-note', ['title' => str_repeat('x', 30), 'card_number' => '4242424242424242'])
        ->assertRedirect('/notes/new')
        ->assertSessionHasErrors(['title', 'body'])
        ->assertSessionHasInput('title', str_repeat('x', 30))
        ->assertSessionMissing('_old_input.card_number');

    expect(Post::query()->count())->toBe(0);
});

it('renders @error and old() on the page the visit lands on', function () {
    $this->actingAs($this->user)
        ->from('/notes/new')
        ->followingRedirects()
        ->post('/actions/create-note', ['title' => str_repeat('x', 30)])
        ->assertOk()
        ->assertSee('The title field must not be greater than 20 characters.')
        ->assertSee('value="'.str_repeat('x', 30).'"', false);
});

it('puts errors in the bag the action names', function () {
    $this->actingAs($this->user)
        ->from('/notes/new')
        ->post('/actions/bagged-note', [])
        ->assertRedirect('/notes/new')
        ->assertSessionHasErrorsIn('createNote', ['title'])
        ->assertSessionDoesntHaveErrors(['title']);
});

it('puts errors in the bag an #[ErrorBag] attribute names', function () {
    if (! class_exists(ErrorBag::class)) {
        $this->markTestSkipped('#[ErrorBag] needs Laravel 13.');
    }

    $this->actingAs($this->user)
        ->from('/notes/new')
        ->post('/actions/attribute-bagged-note', [])
        ->assertRedirect('/notes/new')
        ->assertSessionHasErrorsIn('fromAttribute', ['title']);
});

it('turns a refusal into an error on "action", or on its field, through the app\'s handler', function () {
    $this->actingAs($this->user)
        ->from('/notes/new')
        ->post('/actions/refusing-note')
        ->assertRedirect('/notes/new')
        ->assertSessionHasErrors(['action' => 'This note cannot be saved.']);

    $this->actingAs($this->user)
        ->from('/notes/new')
        ->post('/actions/title-refusing-note', ['title' => 'Taken'])
        ->assertRedirect('/notes/new')
        ->assertSessionHasErrors(['title' => 'That title is taken.'])
        ->assertSessionHasInput('title', 'Taken');

    $this->actingAs($this->user)
        ->from('/notes/new')
        ->post('/actions/keyed-note')
        ->assertRedirect('/notes/new')
        ->assertSessionHasErrors(['action' => trans('agentic-actions::http.idempotency_required')]);
});

it('answers a visit the action refuses to admit with the app\'s 404 and 403 pages', function () {
    $this->actingAs($this->user)->post('/actions/hidden-note')->assertNotFound();

    $this->actingAs($this->user)->post('/actions/late-authorize', ['post_id' => Post::factory()->create()->getKey()])->assertForbidden();
});

/**
 * Lands the visit on the note it created.
 */
#[Expose(web: true)]
final class RedirectingNote extends Action
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
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Save the note.
     */
    public function handle(ActionContext $context, ValidatedInput $input): Post
    {
        return $context->actor(User::class)->posts()->create(['title' => $input->string('title')->toString(), 'body' => 'x', 'status' => 'draft']);
    }

    /**
     * The note's own page.
     */
    public function redirectTo(mixed $result, ActionContext $context): ?string
    {
        return $result instanceof Post ? '/notes/'.$result->getKey() : null;
    }
}

/**
 * Names its own error bag.
 */
#[Expose(web: true)]
final class BaggedNote extends Action
{
    protected ?Effect $effect = Effect::Write;

    protected string $errorBag = 'createNote';

    /**
     * The note's title.
     */
    public function schema(JsonSchema $schema): array
    {
        return ['title' => $schema->string()->required()];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Never reached in these tests.
     */
    public function handle(): null
    {
        return null;
    }
}

/**
 * Names its error bag with Laravel's attribute, which wins over $errorBag.
 */
#[Expose(web: true)]
#[ErrorBag('fromAttribute')]
final class AttributeBaggedNote extends Action
{
    protected ?Effect $effect = Effect::Write;

    protected string $errorBag = 'fromProperty';

    /**
     * The note's title.
     */
    public function schema(JsonSchema $schema): array
    {
        return ['title' => $schema->string()->required()];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Never reached in these tests.
     */
    public function handle(): null
    {
        return null;
    }
}

/**
 * Refuses on the title field.
 */
#[Expose(web: true)]
final class TitleRefusingNote extends Action
{
    protected ?Effect $effect = Effect::Write;

    /**
     * The title.
     */
    public function schema(JsonSchema $schema): array
    {
        return ['title' => $schema->string()->required()];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Refuse the title.
     */
    public function handle(ActionContext $context, ValidatedInput $input): never
    {
        throw Refusal::make('That title is taken.')->on('title');
    }
}
