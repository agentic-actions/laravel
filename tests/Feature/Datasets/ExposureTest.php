<?php

use AgenticActions\ActionContext;
use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Pipeline\Door;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Runner;
use AgenticActions\Surface;
use Illuminate\Support\Facades\Exceptions;
use Tests\Fixtures\Datasets\Invalid\WritingScope;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * A dataset with an error of its own, such as an effect other than Read, has every remote surface closed, as any
 * refused surface stays closed. In production the registry only reports the error, and the doors served the dataset as
 * a Write: its scope() and query ran outside the Read guard.
 */

beforeEach(function () {
    config(['agentic-actions.discovery.paths' => [], 'agentic-actions.discovery.classes' => [WritingScope::class]]);
    $this->refreshActions();
    $this->user = User::factory()->create();
    Post::factory()->for($this->user)->create(['title' => 'Launch']);
});

it('closes every remote surface of a dataset that is not a Read, in production too', function () {
    $this->app->detectEnvironment(fn (): string => 'production');
    Exceptions::fake();
    $this->app->forgetInstance(ActionRegistry::class);

    $entry = app(ActionRegistry::class)->find('writing-scope');

    expect($entry?->errors)->toContain('a dataset is a Read action: remove its $effect')
        ->and($entry?->allows(Surface::Http))->toBeFalse()
        ->and($entry?->allows(Surface::Mcp))->toBeFalse()
        ->and($entry?->allows(Surface::Agent))->toBeFalse();
});

it('never runs such a dataset\'s scope() from a door', function () {
    $outcome = app(Runner::class)->run(ClassExposure::of(WritingScope::class), ['measures' => ['posts']], ActionContext::http($this->user), Door::GeneratedRoute);

    expect($outcome->kind())->not->toBe(OutcomeKind::Ok)
        ->and(Post::query()->where('title', 'Written by a dataset')->exists())->toBeFalse();
});
