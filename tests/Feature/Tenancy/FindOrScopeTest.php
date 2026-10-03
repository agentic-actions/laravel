<?php

use AgenticActions\ActionContext;
use AgenticActions\ActionsManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * ActionContext::find() under a tenant scope whose own conditions hold a top-level or: the scope's conditions stay one
 * group, so the key find() adds narrows the scope's rows instead of joining its or.
 */

it('finds the asked row, and refuses one outside the scope, when the tenant scope holds a top-level or', function () {
    config(['agentic-actions.tenant.model' => Team::class]);

    // A team's own posts, and every published post of any team.
    app(ActionsManager::class)->scopeUsing(fn (Builder $query, Model $tenant): Builder => $query
        ->where('team_id', $tenant->getKey())
        ->orWhere('status', 'published'));

    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $own = Post::factory()->forTeam($team)->create(['status' => 'draft']);
    $shared = Post::factory()->forTeam($other)->create(['status' => 'published']);
    $foreign = Post::factory()->forTeam($other)->create(['status' => 'draft']);

    $context = ActionContext::http(User::factory()->create(), $team);

    expect($context->find(Post::class, $shared->getKey())->is($shared))->toBeTrue()
        ->and($context->find(Post::class, $own->getKey())->is($own))->toBeTrue()
        ->and(fn () => $context->find(Post::class, $foreign->getKey()))->toThrow(ModelNotFoundException::class);
});

it('groups a tenant scope\'s raw condition too, whatever it holds', function () {
    config(['agentic-actions.tenant.model' => Team::class]);

    app(ActionsManager::class)->scopeUsing(fn (Builder $query, Model $tenant): Builder => $query
        ->whereRaw('team_id = ? or status = ?', [$tenant->getKey(), 'published']));

    $team = Team::factory()->create();
    $other = Team::factory()->create();
    Post::factory()->forTeam($team)->create(['status' => 'draft']);
    $foreign = Post::factory()->forTeam($other)->create(['status' => 'draft']);

    expect(fn () => ActionContext::http(User::factory()->create(), $team)->find(Post::class, $foreign->getKey()))->toThrow(ModelNotFoundException::class);
});
