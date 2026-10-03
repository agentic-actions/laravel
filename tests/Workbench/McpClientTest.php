<?php

use Laravel\Mcp\Client;
use Laravel\Mcp\Enums\ProtocolVersion;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Tests\Workbench\Support\KernelBridge;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * Laravel/mcp's own client against the workbench's two MCP paths, with real Sanctum tokens, in both handshake
 * styles. The client's Http calls are replayed through the HTTP kernel (KernelBridge), so the real server answers.
 */

beforeEach(function () {
    KernelBridge::install($this);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create(['slug' => 'acme']);
    $this->other = Team::factory()->create(['slug' => 'other']);
    $this->team->users()->attach($this->user);
    $this->other->users()->attach($this->user);
});

/**
 * A plain-text token for the workbench user with these abilities.
 *
 * @param  list<string>  $abilities
 */
function workbenchToken(array $abilities): string
{
    return test()->user->createToken('client', $abilities)->plainTextToken;
}

it('lists and calls the account tool on the base path', function (ProtocolVersion $version) {
    $client = Client::web(url('/mcp/actions'))->withToken(workbenchToken(['actions:read', 'actions:write']))->withProtocolVersion($version)->connect();

    expect($client->tools()->map->name->values()->all())->toBe(['create-post']);

    $result = $client->callTool('create-post', ['title' => 'From a client', 'body' => 'Hello']);

    expect($result->isError)->toBeFalse()
        ->and($result->text())->toBe('Done.')
        ->and(Post::query()->sole()->only(['user_id', 'team_id', 'title']))->toBe(['user_id' => $this->user->id, 'team_id' => null, 'title' => 'From a client']);
})->with([
    'legacy initialize' => ProtocolVersion::V2025_11_25,
    '2026-07-28 discover' => ProtocolVersion::V2026_07_28,
]);

it('lists the team\'s Read and Write tools on its path, never the Destructive or External ones, and calls both', function (ProtocolVersion $version) {
    $token = workbenchToken(['actions:read', 'actions:write', 'tenant:'.$this->team->id]);
    $client = Client::web(url('/mcp/t/acme'))->withToken($token)->withProtocolVersion($version)->connect();

    expect($client->instructions())->toBe(trans('agentic-actions::mcp.instructions'))
        ->and($client->tools()->map->name->values()->all())->toBe(['import-posts', 'list-team-posts']);

    $imported = $client->callTool('import-posts', ['titles' => ['First', 'Second']]);

    expect($imported->isError)->toBeFalse()
        ->and($imported->text())->toBe('Done.')
        ->and(Post::query()->where('team_id', $this->team->id)->pluck('title')->all())->toBe(['First', 'Second']);

    $listed = $client->callTool('list-team-posts');

    expect($listed->isError)->toBeFalse()
        ->and($listed->text())->toStartWith("Found.\n---")
        ->and($listed->text())->toContain('"title":"Second"');

    expect(fn () => $client->callTool('delete-post', ['post' => Post::query()->value('id')]))
        ->toThrow(JsonRpcException::class, 'Tool [delete-post] not found.')
        ->and(Post::query()->count())->toBe(2);
})->with([
    'legacy initialize' => ProtocolVersion::V2025_11_25,
    '2026-07-28 discover' => ProtocolVersion::V2026_07_28,
]);
