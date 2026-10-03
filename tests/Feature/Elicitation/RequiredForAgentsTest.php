<?php

use AgenticActions\ActionContext;
use AgenticActions\Elicitation\Form;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Facades\Actions;
use AgenticActions\Pipeline\Door;
use AgenticActions\Runner;
use AgenticActions\Schema\AdvertisedSchema;
use AgenticActions\Schema\SchemaReader;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Elicitation\AskingRequired;
use Tests\Fixtures\Elicitation\RequiredAgentVocabulary;
use Workbench\App\Models\User;

/*
 * requiredForAgents(): a model's call must give the fields it names, absent or null alike, and a form asks for them as
 * required; the route, the CLI and the app's own code may still leave them out. RequiredForAgentsMcpTest covers MCP.
 */

beforeEach(function () {
    config(['agentic-actions.discovery.classes' => [AskingRequired::class]]);

    $this->refreshActions();

    AskingRequired::$handled = RequiredAgentVocabulary::$handled = null;

    $this->user = User::factory()->create();
});

it('offers agents the named fields as required and not nullable, and leaves schema() as it was', function () {
    $node = app(AdvertisedSchema::class)->node(new AskingRequired, ActionContext::agent($this->user));
    $canonical = app(SchemaReader::class)->input(new AskingRequired);

    expect($node['required'])->toBe(['title', 'publish_on', 'status'])
        ->and($node['properties']['publish_on']['type'])->toBe('string')
        ->and($canonical['required'])->toBe(['title'])
        ->and($canonical['properties']['publish_on']['type'])->toBe(['string', 'null'])
        ->and(app(AdvertisedSchema::class)->node(new AskingRequired, ActionContext::agent($this->user)->withFixed(['status' => 'draft']))['required'])
        ->toBe(['title', 'publish_on']);
});

it('names agentSchema() fields when the class overrides it, and leaves the canonical input as it was', function () {
    $this->skipUnlessAi();
    $run = fn (array $input) => app(Runner::class)->run(ClassExposure::of(RequiredAgentVocabulary::class), $input, ActionContext::agent($this->user), Door::Agent, ['required']);

    expect(app(AdvertisedSchema::class)->node(new RequiredAgentVocabulary, ActionContext::agent($this->user))['required'])->toBe(['current_title', 'title'])
        ->and($run(['current_title' => 'Launch notes'])->forModel())->toBe('Not done. Rejected: title (required).')
        ->and($run(['current_title' => 'Launch notes', 'title' => 'Launch'])->ok())->toBeTrue()
        ->and(RequiredAgentVocabulary::$handled)->toBe(['post' => 1, 'title' => 'Launch']);

    RequiredAgentVocabulary::run(['post' => 1], ActionContext::http($this->user));

    expect(RequiredAgentVocabulary::$handled)->toBe(['post' => 1]);
});

it('runs in-process and through the generated route without them', function () {
    $this->mountRoutes(fn () => Route::middleware('auth')->group(fn () => Actions::routes()));

    $this->actingAs($this->user)->postJson('/actions/asking-required', ['title' => 'From the web'])->assertOk();

    expect(AskingRequired::$handled)->toBe(['title' => 'From the web']);

    AskingRequired::run(['title' => 'In process', 'publish_on' => null], ActionContext::http($this->user));

    expect(AskingRequired::$handled)->toBe(['title' => 'In process', 'publish_on' => null]);
});
