<?php

namespace Tests\Feature\Http;

use AgenticActions\Events\ActionCompleted;
use AgenticActions\Events\ActionFailed;
use AgenticActions\Events\ActionRefused;
use AgenticActions\Facades\Actions;
use AgenticActions\Surface;
use ArrayObject;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\User;

/*
 * Audit listeners see every HTTP call once: a validation failure inside FormRequest fires ActionRefused like a refusal
 * on any other door does.
 */

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    $this->user = User::factory()->create();
    $this->events = new ArrayObject;

    Event::listen([ActionCompleted::class, ActionRefused::class, ActionFailed::class], function (object $event): void {
        $this->events->append($event);
    });

    $this->mountRoutes(fn () => Route::middleware(['web', 'auth'])->group(fn () => Actions::routes()));
});

it('fires ActionRefused once when FormRequest rejects the input, with the failed rules and no values', function () {
    $this->actingAs($this->user)
        ->postJson('/actions/create-note', ['title' => str_repeat('x', 30)])
        ->assertUnprocessable();

    expect($this->events)->toHaveCount(1);

    $event = $this->events[0];

    expect($event)->toBeInstanceOf(ActionRefused::class)
        ->and($event->action)->toBe('create-note')
        ->and($event->surface)->toBe(Surface::Http)
        ->and($event->reason)->toBe('invalid')
        ->and($event->status)->toBe(422)
        ->and($event->failedRules)->toBe(['title' => ['max'], 'body' => ['required']])
        ->and($event->actorId)->toBe($this->user->getKey())
        ->and($event->durationMs)->toBeGreaterThanOrEqual(0.0)
        ->and(serialize($event))->not->toContain(str_repeat('x', 30));
});

it('fires it for a browser visit too', function () {
    $this->actingAs($this->user)->from('/notes/new')->post('/actions/create-note', [])->assertRedirect('/notes/new');

    expect($this->events)->toHaveCount(1)
        ->and($this->events[0])->toBeInstanceOf(ActionRefused::class)
        ->and($this->events[0]->reason)->toBe('invalid');
});

it('fires nothing for a Precognition request that only checks a draft', function () {
    $this->actingAs($this->user)
        ->postJson('/actions/create-note', ['title' => str_repeat('x', 30)], ['Precognition' => 'true'])
        ->assertUnprocessable();

    $this->actingAs($this->user)
        ->postJson('/actions/create-note', ['title' => 'Hi', 'body' => 'x'], ['Precognition' => 'true'])
        ->assertNoContent();

    expect($this->events)->toHaveCount(0);
});

it('fires one event per call for every other ending', function () {
    $this->actingAs($this->user)->postJson('/actions/create-note', ['title' => 'Hi', 'body' => 'x'])->assertOk();
    $this->actingAs($this->user)->postJson('/actions/hidden-note')->assertNotFound();
    $this->actingAs($this->user)->postJson('/actions/refusing-note')->assertStatus(409);
    $this->actingAs($this->user)->postJson('/actions/crashing-note')->assertServerError();

    expect(array_map(fn (object $event): string => $event::class, $this->events->getArrayCopy()))->toBe([
        ActionCompleted::class,
        ActionRefused::class,
        ActionRefused::class,
        ActionFailed::class,
    ])
        ->and($this->events[1]->reason)->toBe('not_found')
        ->and($this->events[2]->reason)->toBe('refused');
});
