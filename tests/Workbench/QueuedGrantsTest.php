<?php

use AgenticActions\Events\ActionCompleted;
use AgenticActions\Events\ActionRefused;
use AgenticActions\Queue\RunAction;
use AgenticActions\Surface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Workbench\App\Actions\ImportPosts;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * The workbench's token route queues ImportPosts on the database queue, and a worker runs it later as the token's
 * person, in the URL's team, with the abilities the token had when the job was queued.
 */

beforeEach(function () {
    Event::fake([ActionCompleted::class, ActionRefused::class]);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create(['slug' => 'acme']);
    $this->team->users()->attach($this->user);
});

/**
 * Queue an import through the token route with these abilities, end the request, then run one worker pass.
 *
 * @param  list<string>  $abilities
 */
function queueImportAndWork(array $abilities): void
{
    $token = test()->user->createToken('importer', $abilities)->plainTextToken;

    test()->withToken($token)
        ->postJson('/api/teams/acme/imports', ['titles' => ['Queued']])
        ->assertAccepted();

    $payload = json_decode((string) DB::table('jobs')->value('payload'), true);

    expect(DB::table('jobs')->count())->toBe(1)
        ->and($payload['displayName'])->toBe(RunAction::class)
        ->and($payload['data']['command'])->not->toContain('Queued');

    app('auth')->forgetGuards();

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);

    expect(DB::table('jobs')->count())->toBe(0);
}

it('queues the import for a read-only token, and the worker refuses it', function () {
    queueImportAndWork(['actions:read', 'tenant:'.$this->team->id]);

    expect(Post::query()->count())->toBe(0);

    Event::assertNotDispatched(ActionCompleted::class);
    Event::assertDispatched(ActionRefused::class, fn (ActionRefused $event): bool => $event->class === ImportPosts::class
        && $event->reason === 'not_found'
        && $event->surface === Surface::Queue);
});

it('runs the import for a token that can write, as its person, in the URL\'s team', function () {
    queueImportAndWork(['actions:write', 'tenant:'.$this->team->id]);

    expect(Post::query()->sole()->only(['user_id', 'team_id', 'title', 'status']))
        ->toBe(['user_id' => $this->user->id, 'team_id' => $this->team->id, 'title' => 'Queued', 'status' => 'draft']);

    Event::assertDispatched(ActionCompleted::class, fn (ActionCompleted $event): bool => $event->surface === Surface::Queue);
});

it('refuses the import in the worker for a token bound to another team', function () {
    $other = Team::factory()->create(['slug' => 'other']);
    $other->users()->attach($this->user);

    queueImportAndWork(['actions:write', 'tenant:'.$other->id]);

    expect(Post::query()->count())->toBe(0);

    Event::assertDispatched(ActionRefused::class, fn (ActionRefused $event): bool => $event->reason === 'not_found');
});
