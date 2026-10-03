<?php

use AgenticActions\Tenancy\SpatieTeams;
use Spatie\Permission\PermissionRegistrar;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->registrar = app(PermissionRegistrar::class);
    $this->registrar->setPermissionsTeamId('previous');
});

it('sets the team to the tenant\'s key for the callback and restores it after', function () {
    $team = Team::factory()->make(['id' => 12]);

    $seen = (new SpatieTeams)->run($team, null, fn () => $this->registrar->getPermissionsTeamId());

    expect($seen)->toBe(12)
        ->and($this->registrar->getPermissionsTeamId())->toBe('previous');
});

it('restores the team after an exception', function () {
    $team = Team::factory()->make(['id' => 12]);

    expect(fn () => (new SpatieTeams)->run($team, null, fn () => throw new RuntimeException('Step failed.')))
        ->toThrow(RuntimeException::class, 'Step failed.')
        ->and($this->registrar->getPermissionsTeamId())->toBe('previous');
});

it('forgets the actor\'s cached roles and permissions on entry and on exit', function () {
    $team = Team::factory()->make(['id' => 12]);
    $user = User::factory()->make();
    $user->setRelation('roles', collect(['stale']));
    $user->setRelation('permissions', collect(['stale']));

    $during = (new SpatieTeams)->run($team, $user, function () use ($user): array {
        $loaded = [$user->relationLoaded('roles'), $user->relationLoaded('permissions')];

        $user->setRelation('roles', collect(['for team 12']));

        return $loaded;
    });

    expect($during)->toBe([false, false])
        ->and($user->relationLoaded('roles'))->toBeFalse()
        ->and($user->relationLoaded('permissions'))->toBeFalse();
});

it('changes nothing without a tenant', function () {
    $user = User::factory()->make();
    $user->setRelation('roles', collect(['kept']));

    $seen = (new SpatieTeams)->run(null, $user, fn () => $this->registrar->getPermissionsTeamId());

    expect($seen)->toBe('previous')
        ->and($user->relationLoaded('roles'))->toBeTrue();
});
