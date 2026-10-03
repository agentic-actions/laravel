<?php

use AgenticActions\ActionContext;
use AgenticActions\ActionsManager;
use AgenticActions\Facades\Actions;
use AgenticActions\Pipeline\OutcomeKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Datasets\AccountPosts;
use Tests\Fixtures\Datasets\Comment;
use Tests\Fixtures\Datasets\CommentsByPost;
use Tests\Fixtures\Datasets\PostAuthors;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * Inside a tenant a dataset counts only the tenant's rows, as find() reads them, whether or not it is tenant-scoped,
 * and reads each related row in the tenant's scope too, unless it names the relation among the shared ones. An
 * account-level dataset offered to an agent built for one team read every team's rows, and a link to another team's
 * row showed that row's column.
 */

beforeEach(function () {
    config(['agentic-actions.discovery.paths' => [], 'agentic-actions.discovery.classes' => [AccountPosts::class]]);
    $this->useTeamTenancy();
    $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->blue = Team::factory()->create(['name' => 'Blue']);
    $this->red = Team::factory()->create(['name' => 'Red']);
    $this->blue->users()->attach($this->user);

    Post::factory()->for($this->user)->forTeam($this->blue)->create(['status' => 'draft']);
    $this->redPost = Post::factory()->forTeam($this->red)->create(['status' => 'published']);
    Post::factory()->forTeam($this->red)->create(['status' => 'published']);
    PostAuthors::$sharing = [];
});

afterEach(function () {
    Schema::dropIfExists('comments');
    PostAuthors::$sharing = [];
});

it('counts only the tenant\'s rows inside a tenant, as find() stays in it, though the dataset is account-level', function () {
    $context = ActionContext::http($this->user, $this->blue);

    expect(fn () => $context->find(Post::class, $this->redPost->getKey()))->toThrow(ModelNotFoundException::class);

    $outcome = Actions::attempt(AccountPosts::class, ['measures' => ['posts'], 'by' => ['team']], $context);

    expect($outcome->kind())->toBe(OutcomeKind::Ok, (string) $outcome->exception()?->getMessage())
        ->and(array_column($outcome->output()['rows'], 'posts', 'team'))->toBe(['Blue' => 1]);
});

it('counts every team\'s rows outside a tenant, where only the dataset\'s own scope() could narrow them', function () {
    $outcome = Actions::attempt(AccountPosts::class, ['measures' => ['posts'], 'by' => ['team']], ActionContext::http($this->user));

    expect(array_column($outcome->output()['rows'], 'posts', 'team'))->toBe(['Red' => 2, 'Blue' => 1]);
});

it('reads a related row in the tenant\'s scope too, so a link to another tenant\'s row shows no value', function () {
    // A temporary table, which MySQL creates without committing the test's transaction.
    Schema::create('comments', function (Blueprint $table): void {
        $table->temporary();
        $table->id();
        $table->foreignId('team_id');
        $table->foreignId('post_id')->nullable();
        $table->timestamps();
    });

    // Comments and posts both carry team_id, so one scope answers for both.
    config(['agentic-actions.tenant.membership' => null, 'agentic-actions.tenant.scope' => null, 'agentic-actions.discovery.classes' => [CommentsByPost::class]]);
    $this->refreshActions();
    app(ActionsManager::class)->membershipUsing(fn (User $actor, Team $team): bool => $team->users()->whereKey($actor->getKey())->exists());
    app(ActionsManager::class)->scopeUsing(fn (Builder $query, Model $tenant): Builder => $query->where($query->getModel()->qualifyColumn('team_id'), $tenant->getKey()));

    $launch = Post::factory()->for($this->user)->forTeam($this->blue)->create(['title' => 'Launch plan']);
    $this->redPost->update(['title' => 'Red team: the acquisition, do not share']);

    // A Blue comment on Blue's post, and one whose post_id names Red's: a link nobody checked the tenant of.
    Comment::query()->create(['team_id' => $this->blue->getKey(), 'post_id' => $launch->getKey()]);
    Comment::query()->create(['team_id' => $this->blue->getKey(), 'post_id' => $this->redPost->getKey()]);

    $outcome = Actions::attempt(CommentsByPost::class, ['measures' => ['comments'], 'by' => ['post']], ActionContext::http($this->user, $this->blue));

    expect($outcome->kind())->toBe(OutcomeKind::Ok, (string) $outcome->exception()?->getMessage())
        ->and($outcome->output()['rows'])->toBe([['post' => 'Launch plan', 'comments' => 1], ['post' => null, 'comments' => 1]]);
});

it('reads a relation named in $shared without the tenant\'s scope, and fails one the scope refuses', function () {
    config(['agentic-actions.discovery.classes' => [PostAuthors::class]]);
    $this->refreshActions();
    $context = ActionContext::http($this->user, $this->blue);

    $refused = Actions::attempt(PostAuthors::class, ['measures' => ['posts'], 'by' => ['author']], $context);

    PostAuthors::$sharing = ['user'];
    $shared = Actions::attempt(PostAuthors::class, ['measures' => ['posts'], 'by' => ['author']], $context);

    expect($refused->kind())->toBe(OutcomeKind::Failed)
        ->and($refused->exception()?->getMessage())->toBe('TeamScope cannot scope '.User::class.'.')
        ->and($shared->kind())->toBe(OutcomeKind::Ok, (string) $shared->exception()?->getMessage())
        ->and($shared->output()['rows'])->toBe([['author' => $this->user->name, 'posts' => 1]]);
});
