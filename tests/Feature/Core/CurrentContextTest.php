<?php

use AgenticActions\ActionContext;
use AgenticActions\Surface;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Actions\TracedNote;
use Tests\Fixtures\Context\RememberContext;
use Workbench\App\Models\User;

/*
 * ActionContext::current(): the context of the action running now, for code an action calls that is not handed it
 * (a model event, a service): the innermost run, the one around it again after a nested run, null outside any run,
 * and null again once an exception has left one, in-process or through the HTTP bridge.
 */

beforeEach(function () {
    RememberContext::$seen = [];
    TracedNote::reset();
});

it('is null outside any run', function () {
    expect(ActionContext::current())->toBeNull();
});

it('is the running action\'s context, with its surface and actor', function () {
    $context = ActionContext::agent(User::factory()->create());

    RememberContext::run([], $context);

    expect(RememberContext::$seen[0]?->surface)->toBe(Surface::Agent)
        ->and(RememberContext::$seen[0]?->actor?->getAuthIdentifier())->toBe($context->actor?->getAuthIdentifier())
        ->and(ActionContext::current())->toBeNull();
});

it('is the innermost run inside a nested one, and the outer one again after it', function () {
    RememberContext::run(['nested' => true], ActionContext::http(User::factory()->create()));

    expect(array_map(fn (?ActionContext $context): ?Surface => $context?->surface, RememberContext::$seen))
        ->toBe([Surface::Http, Surface::System, Surface::Http]);
});

it('is null again after an exception left a run', function () {
    expect(fn () => RememberContext::run(['throws' => true], ActionContext::http(null)))->toThrow(RuntimeException::class, 'Broken.')
        ->and(RememberContext::$seen[0]?->surface)->toBe(Surface::Http)
        ->and(ActionContext::current())->toBeNull();
});

it('is null again after the HTTP bridge refused a call', function (bool $authorizes, string $title, int $status) {
    $seen = null;
    TracedNote::$authorizes = function () use (&$seen, $authorizes): bool {
        $seen = ActionContext::current();

        return $authorizes;
    };

    $this->mountRoutes(fn () => Route::post('traced', TracedNote::class));

    $this->actingAs(User::factory()->create())->postJson('/traced', ['title' => $title])->assertStatus($status);

    expect($seen?->surface)->toBe(Surface::Http)
        ->and(ActionContext::current())->toBeNull();
})->with([
    'denied by an input-free authorize(): 403' => [false, 'Hello', 403],
    'invalid input: 422' => [true, str_repeat('x', 30), 422],
]);
