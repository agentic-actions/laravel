<?php

use AgenticActions\ActionContext;
use AgenticActions\Facades\Actions;
use AgenticActions\Pipeline\OutcomeKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Datasets\Posts;
use Tests\Fixtures\Security\TagNotes;
use Workbench\App\Models\User;

/*
 * A list its rules cap (array or list, and max:N) reaches the validator with at most N + 1 items, and the same max rule
 * refuses the call.
 */

beforeEach(function () {
    config(['agentic-actions.discovery.paths' => [], 'agentic-actions.discovery.classes' => [TagNotes::class, Posts::class]]);
    $this->refreshActions();
    [Posts::$scope, Posts::$zone] = [null, ''];
    $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
});

it('validates no item past a max its own rules() declare', function () {
    $outcome = Actions::attempt(TagNotes::class, ['tags' => array_fill(0, 5000, 'launch')], ActionContext::http($this->user));

    expect($outcome->kind())->toBe(OutcomeKind::Invalid, (string) $outcome->exception()?->getMessage())
        ->and(array_keys($outcome->errors()))->toEqualCanonicalizing(['tags', 'tags.0', 'tags.1', 'tags.2', 'tags.3']);
});

it('validates no item past a max a generated schema declares, at any depth', function (array $input, array $keys) {
    $outcome = Actions::attempt(Posts::class, $input, ActionContext::http($this->user));

    expect($outcome->kind())->toBe(OutcomeKind::Invalid, (string) $outcome->exception()?->getMessage())
        ->and(array_diff(array_keys($outcome->errors()), $keys))->toBe([]);
})->with([
    'measures' => [['measures' => array_fill(0, 20000, 'posts')], ['measures', 'measures.0', 'measures.1', 'measures.2', 'measures.3', 'measures.4', 'measures.5']],
    'filters' => [['measures' => ['posts'], 'filters' => array_fill(0, 20000, ['dimension' => 'team', 'values' => ['Blue']])], ['filters', ...array_map(fn (int $index): string => "filters.{$index}.dimension", range(0, 5))]],
    'a filter\'s values' => [['measures' => ['posts'], 'filters' => [['dimension' => 'team', 'values' => array_fill(0, 20000, 'Blue')]]], ['filters.0.values']],
]);

it('caps a list on a generated web route too, before the FormRequest validates it', function () {
    // The web door validates through a FormRequest of its own; its input is capped as every other door's is.
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    $this->mountRoutes(fn () => Route::middleware(['web', 'auth'])->group(fn () => Actions::routes()));

    $errors = $this->actingAs($this->user)
        ->postJson('/actions/tag-notes', ['tags' => array_fill(0, 5000, 'launch')])
        ->assertUnprocessable()
        ->json('errors');

    expect(array_keys($errors))->toEqualCanonicalizing(['tags', 'tags.0', 'tags.1', 'tags.2', 'tags.3']);
});

it('leaves a list within its cap as it came', function () {
    $outcome = Actions::attempt(TagNotes::class, ['tags' => ['launch', 'pricing', 'hiring']], ActionContext::http($this->user));

    expect($outcome->kind())->toBe(OutcomeKind::Ok, (string) $outcome->exception()?->getMessage())
        ->and($outcome->output())->toBe(['tags' => ['launch', 'pricing', 'hiring']]);
});
