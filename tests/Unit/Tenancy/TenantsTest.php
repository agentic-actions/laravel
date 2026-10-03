<?php

use AgenticActions\ActionsManager;
use AgenticActions\Effect;
use AgenticActions\MissingContext;
use AgenticActions\Tenancy\Tenants;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

it('resolves a tenant by its route key', function () {
    $this->useTeamTenancy();
    $team = Team::factory()->create(['slug' => 'acme']);

    expect(Tenants::resolve('acme')?->is($team))->toBeTrue()
        ->and(Tenants::resolve((string) $team->getKey()))->toBeNull()
        ->and(Tenants::resolve('nobody'))->toBeNull();
});

it('resolves a tenant by id when its route key is the id', function () {
    config(['agentic-actions.tenant.model' => User::class]);
    $user = User::factory()->create();

    expect(Tenants::resolve($user->getKey())?->is($user))->toBeTrue()
        ->and(Tenants::resolve((string) $user->getKey())?->is($user))->toBeTrue();
});

it('resolves nothing without a tenant model', function () {
    expect(Tenants::resolve('acme'))->toBeNull()
        ->and(Tenants::foreignKey())->toBeNull();
});

it('decides membership with the class, then the closure, then false', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->users()->attach($user);
    $archived = Team::factory()->create(['slug' => 'archived-old']);
    $archived->users()->attach($user);

    expect(Tenants::member($user, $team, Effect::Write))->toBeFalse()
        ->and(Tenants::hasMembership())->toBeFalse();

    app(ActionsManager::class)->membershipUsing(fn (User $actor, Team $tenant, ?Effect $effect): bool => $effect === Effect::Read);

    expect(Tenants::member($user, $team, Effect::Read))->toBeTrue()
        ->and(Tenants::member($user, $team, Effect::Write))->toBeFalse()
        ->and(Tenants::hasMembership())->toBeTrue();

    $this->useTeamTenancy();

    expect(Tenants::member($user, $team, Effect::Write))->toBeTrue()
        ->and(Tenants::member($user, $archived, Effect::Write))->toBeFalse()
        ->and(Tenants::member($user, $archived, Effect::Read))->toBeTrue()
        ->and(Tenants::member($user, $archived, null))->toBeTrue()
        ->and(Tenants::member(User::factory()->create(), $team, Effect::Read))->toBeFalse();
});

it('scopes with the class, then the closure, then MissingContext', function () {
    $team = Team::factory()->create();
    $post = Post::factory()->forTeam($team)->create();
    Post::factory()->create();

    expect(fn () => Tenants::scope(Post::query(), $team))->toThrow(MissingContext::class);

    app(ActionsManager::class)->scopeUsing(fn (Builder $query, Model $tenant): Builder => $query->where('team_id', $tenant->getKey()));

    expect(Tenants::scope(Post::query(), $team)->pluck('id')->all())->toBe([$post->getKey()]);

    $this->useTeamTenancy();

    expect(Tenants::scope(Post::query(), $team)->pluck('id')->all())->toBe([$post->getKey()])
        ->and(fn () => Tenants::scope(User::query(), $team))->toThrow(LogicException::class, 'TeamScope cannot scope');
});

it('names the tenant model\'s foreign key', function () {
    $this->useTeamTenancy();

    expect(Tenants::foreignKey())->toBe('team_id');
});
