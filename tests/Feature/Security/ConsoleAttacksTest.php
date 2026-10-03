<?php

namespace Tests\Feature\Security;

use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * actions:run is an operator's tool: it skips only the token check. A tenant still needs a member, and a model
 * that reaches the command through a tool of its own still meets the model gate.
 */

beforeEach(function () {
    // The default guard's provider must hand back the workbench's users.
    config(['auth.providers.users.model' => User::class]);

    $this->useTeamTenancy();

    $this->member = User::factory()->create();
    $this->stranger = User::factory()->create();

    $team = Team::factory()->create(['slug' => 'acme']);
    $team->users()->attach($this->member);
});

it('refuses a tenant without an actor, and one the actor is not a member of', function () {
    $this->artisan('actions:run', ['name' => 'team-note', 'input' => ['title=Hi'], '--tenant' => 'acme'])->assertExitCode(1);
    $this->artisan('actions:run', ['name' => 'team-note', 'input' => ['title=Hi'], '--tenant' => 'acme', '--as' => (string) $this->stranger->getKey()])->assertExitCode(1);

    expect(Post::query()->count())->toBe(0);

    $this->artisan('actions:run', ['name' => 'team-note', 'input' => ['title=Hi'], '--tenant' => 'acme', '--as' => (string) $this->member->getKey()])->assertExitCode(0);

    expect(Post::query()->sole()->user_id)->toBe($this->member->getKey());
});

it('refuses a locale that could leave the language directories', function () {
    $this->artisan('actions:run', ['name' => 'team-note', 'input' => ['title=Hi'], '--tenant' => 'acme', '--as' => (string) $this->member->getKey(), '--locale' => '../../../tmp'])
        ->expectsOutputToContain('Invalid characters present in locale.')
        ->assertExitCode(1);

    expect(Post::query()->count())->toBe(0);
});
