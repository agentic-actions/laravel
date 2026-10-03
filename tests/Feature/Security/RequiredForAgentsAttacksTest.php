<?php

use AgenticActions\ActionContext;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Facades\Actions;
use AgenticActions\Pipeline\Door;
use AgenticActions\Runner;
use AgenticActions\TypeScript\Emitter;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Elicitation\AskingRequired;
use Tests\Fixtures\Elicitation\RequiredAgentVocabulary;
use Workbench\App\Models\User;

/*
 * The 0.6 security review of requiredForAgents(). A prompt-injected model tries to meet the requirement with nothing,
 * to loosen a named field's other rules or to write a field the edge fixes; a browser checks that the requirement
 * never reaches the web, Precognition included. McpFormAttacksTest covers the MCP door.
 */

beforeEach(function () {
    config(['agentic-actions.discovery.classes' => [AskingRequired::class]]);

    $this->refreshActions();

    AskingRequired::$handled = RequiredAgentVocabulary::$handled = null;

    $this->user = User::factory()->create();
});

it('never requires the named fields of the generated route, its Precognition check or its TypeScript function, and keeps the web\'s own rules', function () {
    $this->mountRoutes(fn () => Route::middleware('auth')->group(fn () => Actions::routes()));

    $this->actingAs($this->user)->postJson('/actions/asking-required', ['title' => 'Draft'], ['Precognition' => 'true'])->assertNoContent();
    $this->actingAs($this->user)->postJson('/actions/asking-required', ['title' => 'Draft'], ['Precognition' => 'true', 'Precognition-Validate-Only' => 'publish_on,status'])->assertNoContent();
    $this->actingAs($this->user)->postJson('/actions/asking-required', ['title' => 'Draft', 'status' => 'archived'])->assertUnprocessable()->assertJsonValidationErrors(['status']);

    expect(AskingRequired::$handled)->toBeNull()
        ->and(app(Emitter::class)->render())->toContain('publish_on?: ')->toContain('status?: ')->toContain('title: ');
});

it('refuses a model\'s empty, blank or null value for a named field, as it refuses an absent one', function (array $values) {
    $this->skipUnlessAi();

    $outcome = app(Runner::class)->run(ClassExposure::of(AskingRequired::class), ['title' => 'Launch notes', ...$values], ActionContext::agent($this->user), Door::Agent, ['default']);

    expect($outcome->forModel())->toBe('Not done. Rejected: publish_on (required), status (required).')
        ->and(AskingRequired::$handled)->toBeNull();
})->with([
    'empty strings' => [['publish_on' => '', 'status' => '']],
    'blank strings' => [['publish_on' => '   ', 'status' => "\t"]],
    'empty lists' => [['publish_on' => [], 'status' => []]],
    'nulls' => [['publish_on' => null, 'status' => null]],
]);

it('keeps every other rule of a named field on a model\'s call', function () {
    $this->skipUnlessAi();

    $outcome = app(Runner::class)->run(ClassExposure::of(AskingRequired::class), ['title' => 'Launch notes', 'publish_on' => 'next week', 'status' => 'archived'], ActionContext::agent($this->user), Door::Agent, ['default']);

    expect($outcome->forModel())->toBe('Not done. Rejected: publish_on (date_format), status (in).')
        ->and(AskingRequired::$handled)->toBeNull();
});

it('gives a named field the edge fixes the edge\'s value, never the model\'s', function () {
    $this->skipUnlessAi();
    $context = ActionContext::agent($this->user)->withFixed(['status' => 'draft']);

    $outcome = app(Runner::class)->run(ClassExposure::of(AskingRequired::class), ['title' => 'Launch notes', 'publish_on' => '2026-10-01', 'status' => 'published'], $context, Door::Agent, ['default']);

    expect($outcome->ok())->toBeTrue()
        ->and(AskingRequired::$handled)->toBe(['title' => 'Launch notes', 'publish_on' => '2026-10-01', 'status' => 'draft']);
});

it('refuses a null for a named agentSchema() key before fromAgent() runs', function () {
    $this->skipUnlessAi();

    $outcome = app(Runner::class)->run(ClassExposure::of(RequiredAgentVocabulary::class), ['current_title' => 'Launch notes', 'title' => null], ActionContext::agent($this->user), Door::Agent, ['required']);

    expect($outcome->forModel())->toBe('Not done. Rejected: title (required).')
        ->and(RequiredAgentVocabulary::$handled)->toBeNull();
});
