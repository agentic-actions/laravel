<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Client;
use Symfony\Component\HttpFoundation\Response;
use Tests\Workbench\Support\KernelBridge;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * The server half: a member's open team page polls the feed, and writes made elsewhere (a queued import run by a
 * worker, an MCP client of another member) are in its next poll's touches. feed.test.mjs is the client half: a
 * polled touch reaches apply() within one interval.
 */

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->teammate = User::factory()->create();
    $this->team = Team::factory()->create(['slug' => 'acme']);
    $this->team->users()->attach([$this->member->id, $this->teammate->id]);
});

/**
 * Poll the team's feed as the member's open page does, signed in with a session: no bearer header, and the web guard,
 * since a token request earlier in the test made sanctum the default guard.
 *
 * @return TestResponse<Response>
 */
function pollTeamFeed(?int $since): TestResponse
{
    return test()->withoutToken()
        ->actingAs(test()->member, 'web')
        ->postJson('/teams/acme/actions/_changes', ['since' => $since])
        ->assertOk();
}

it('answers the member\'s next poll with the key a queued import touched, once a worker ran it', function () {
    $first = pollTeamFeed(null)->assertJsonPath('touches', []);

    // The teammate's token request carries no session: forget the member the test signed in (13's harness facts).
    app('auth')->forgetGuards();

    $token = $this->teammate->createToken('importer', ['actions:write', 'tenant:'.$this->team->id])->plainTextToken;

    $this->withToken($token)->postJson('/api/teams/acme/imports', ['titles' => ['Queued']])->assertAccepted();

    pollTeamFeed($first->json('now'))->assertJsonPath('touches', []);

    app('auth')->forgetGuards();

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);

    expect(Post::query()->sole()->user_id)->toBe($this->teammate->id);

    $next = pollTeamFeed($first->json('now'))->assertJsonPath('touches', ['posts']);

    // The cursor trails the clock by 2 s, so a poll soon after may hear of the write again; once past it, nothing.
    $this->travel(3)->seconds();

    $later = pollTeamFeed($next->json('now'));

    pollTeamFeed($later->json('now'))->assertJsonPath('touches', []);
});

it('answers the member\'s next poll with the key another member\'s MCP call touched', function () {
    $first = pollTeamFeed(null)->assertJsonPath('touches', []);

    KernelBridge::install($this);

    $token = $this->teammate->createToken('client', ['actions:read', 'actions:write', 'tenant:'.$this->team->id])->plainTextToken;
    $result = Client::web(url('/mcp/t/acme'))->withToken($token)->connect()->callTool('import-posts', ['titles' => ['Over MCP']]);

    expect($result->text())->toBe('Done.');

    pollTeamFeed($first->json('now'))->assertJsonPath('touches', ['posts']);
});
