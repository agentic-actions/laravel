<?php

use AgenticActions\ActionContext;
use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Facades\Actions;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Support\Packages;
use AgenticActions\Support\PackageStatus;
use AgenticActions\Surface;
use Illuminate\Support\Facades\Auth;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * Actions::call(), the one door every agent tool call enters, in the two branches only it has: a name the registry
 * does not know, and a class whose live facts no longer allow agents whatever the registry nominated. The rest of the
 * agent door (toolsets, the forced Agent surface, the model gate, Translate, the prune) is the Runner's, in
 * Core/RunnerTest.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    Auth::shouldUse('web');

    $this->user = User::factory()->create();
    $this->note = ['title' => 'Hi', 'body' => 'x'];
});

it('refuses an unknown name with the fixed sentence', function () {
    $outcome = Actions::call(['default'], 'drop-every-table', [], ActionContext::agent($this->user));

    expect($outcome->kind())->toBe(OutcomeKind::NotFound)
        ->and($outcome->entry()->name)->toBe('drop-every-table')
        ->and($outcome->context()->surface)->toBe(Surface::Agent)
        ->and($outcome->forModel())->toBe('Not done: that action is not available here. Do not try it again.');
});

it('refuses a class that no longer allows agents, whatever the registry nominated', function () {
    expect(app(ActionRegistry::class)->find('create-note')?->allows(Surface::Agent))->toBeTrue();

    // The registry stays as loaded; only the live class facts change, as after `composer remove laravel/ai`.
    ClassExposure::flush();
    $this->app->instance(Packages::class, new Packages(['laravel/ai' => PackageStatus::Missing] + $this->packageDefaults()));

    $outcome = Actions::call(['default'], 'create-note', $this->note, ActionContext::agent($this->user));

    expect(app(ActionRegistry::class)->find('create-note')?->allows(Surface::Agent))->toBeTrue()
        ->and($outcome->kind())->toBe(OutcomeKind::NotFound)
        ->and(Post::query()->count())->toBe(0);
});
