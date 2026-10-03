<?php

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Events\ActionCompleted;
use AgenticActions\Events\ActionRefused;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Outcome;
use AgenticActions\Pipeline\Door;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Runner;
use AgenticActions\Surface;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\DeleteNote;
use Tests\Fixtures\Actions\RefusingNote;
use Tests\Fixtures\Mcp\Actions\McpReadPosts;
use Tests\Fixtures\Mcp\Actions\McpTeamRead;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * The Runner's MCP door, below the listing: whatever context a caller hands it, a call runs on the Mcp surface as a
 * model's, and the door itself refuses what the listing never shows, so no caller of the Runner can skip the listing.
 */

beforeEach(function () {
    $this->user = User::factory()->create();

    Sanctum::actingAs($this->user, ['actions:read', 'actions:write', 'actions:destructive']);
});

/**
 * Run a class through one of the Runner's doors.
 *
 * @param  class-string<Action>  $class
 * @param  array<string, mixed>  $input
 */
function mcpDoorRun(string $class, array $input, ActionContext $context, Door $door = Door::Mcp): Outcome
{
    return app(Runner::class)->run(ClassExposure::of($class), $input, $context, $door, ['default']);
}

it('runs as the Mcp surface, model-driven, whatever context it is handed, and says so on a refusal too', function () {
    Event::fake([ActionCompleted::class, ActionRefused::class]);

    $outcome = mcpDoorRun(CreateNote::class, ['title' => 'Hi', 'body' => 'x'], ActionContext::http($this->user));

    expect($outcome->kind())->toBe(OutcomeKind::Ok)
        ->and($outcome->context()->surface)->toBe(Surface::Mcp)
        ->and($outcome->forModel())->toBe('Done.')
        ->and(Post::query()->sole()->title)->toBe('Hi');

    mcpDoorRun(DeleteNote::class, ['note' => 1], ActionContext::http($this->user));

    Event::assertDispatched(ActionCompleted::class, fn (ActionCompleted $event): bool => $event->surface === Surface::Mcp && $event->modelDriven);
    Event::assertDispatched(ActionRefused::class, fn (ActionRefused $event): bool => $event->surface === Surface::Mcp && $event->modelDriven && $event->reason === 'not_found');
});

it('refuses as not found, running nothing, what the listing never shows', function (Closure $call) {
    $post = $this->user->posts()->create(['title' => 'Keep', 'body' => 'x', 'status' => 'draft']);

    expect($call->call($this, $post)->kind())->toBe(OutcomeKind::NotFound)
        ->and(Post::query()->pluck('title')->all())->toBe(['Keep']);
})->with([
    'a class that does not allow MCP' => [fn (): Outcome => mcpDoorRun(RefusingNote::class, [], ActionContext::mcp($this->user, null))],
    'a Destructive action, whatever the token grants' => [fn (Post $post): Outcome => mcpDoorRun(DeleteNote::class, ['note' => $post->id], ActionContext::mcp($this->user, null))],
    'any action while surfaces.mcp is off' => [function (): Outcome {
        config(['agentic-actions.surfaces.mcp' => false]);

        return mcpDoorRun(CreateNote::class, ['title' => 'Hi', 'body' => 'x'], ActionContext::mcp($this->user, null));
    }],
]);

it('runs a tenant-scoped action only with a tenant and any other only without one, on the MCP door alone', function (string $class, bool $withTenant, Door $door, OutcomeKind $kind) {
    if ($door === Door::Agent) {
        $this->skipUnlessAi();
    }

    $this->useTeamTenancy();

    $team = Team::factory()->create();
    $team->users()->attach($this->user);

    $tenant = $withTenant ? $team : null;
    $context = $door === Door::Agent ? ActionContext::agent($this->user, $tenant) : ActionContext::mcp($this->user, $tenant);

    expect(mcpDoorRun($class, [], $context, $door)->kind())->toBe($kind);
})->with([
    'an account action without a tenant' => [McpReadPosts::class, false, Door::Mcp, OutcomeKind::Ok],
    'an account action with a tenant' => [McpReadPosts::class, true, Door::Mcp, OutcomeKind::NotFound],
    'a tenant action without a tenant' => [McpTeamRead::class, false, Door::Mcp, OutcomeKind::NotFound],
    'a tenant action with its tenant' => [McpTeamRead::class, true, Door::Mcp, OutcomeKind::Ok],
    'an account action with a tenant, on the agent door, which does not split' => [McpReadPosts::class, true, Door::Agent, OutcomeKind::Ok],
]);
