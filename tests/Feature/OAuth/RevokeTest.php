<?php

use AgenticActions\OAuth\McpConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Tests\Fixtures\OAuth\Flow;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * A person's connections are a query over theirs only, and revoking one ends its client's tokens and codes for that
 * person at once, and only for that person.
 */

beforeEach(function () {
    $this->useOAuth(Flow::teams());

    $this->alice = User::factory()->create();
    $this->bob = User::factory()->create();
    $this->acme = Team::factory()->create(['slug' => 'acme']);
    $this->other = Team::factory()->create(['slug' => 'other']);
    $this->acme->users()->attach([$this->alice->id, $this->bob->id]);
    $this->other->users()->attach($this->alice);
});

it('lists a person\'s connections only, newest first, as a query', function () {
    Carbon::setTestNow('2026-09-27 10:00:00');
    (new Flow($this))->connect($this->alice, 'http://localhost/mcp/t/acme');
    (new Flow($this))->connect($this->bob, 'http://localhost/mcp/t/acme');
    Carbon::setTestNow('2026-09-27 11:00:00');
    $newest = new Flow($this);
    $newest->connect($this->alice, 'http://localhost/mcp/t/other');

    $query = McpConnection::for($this->alice);

    expect($query)->toBeInstanceOf(Builder::class)
        ->and($query->pluck('tenant_id')->all())->toBe([$this->other->id, $this->acme->id])
        ->and($query->with('client', 'tenant')->first())
        ->client->getKey()->toBe($newest->client)
        ->tenant->is($this->other)->toBeTrue();
});

it('ends the access token, the refresh token and an unused code, and removes the connection, leaving another person\'s tokens', function () {
    $flow = new Flow($this);
    $alice = $flow->connect($this->alice, 'http://localhost/mcp/t/acme');
    $aliceRefresh = $flow->tokens['refresh_token'];
    $bob = $flow->connect($this->bob, 'http://localhost/mcp/t/acme');

    McpConnection::for($this->alice)->sole()->revoke();

    expect(McpConnection::for($this->alice)->exists())->toBeFalse()
        ->and($flow->tools($bob, '/mcp/t/acme'))->toBe(['create-team-post', 'list-team-posts']);

    $flow->mcp($alice, '/mcp/t/acme')->assertUnauthorized();
    $flow->refresh(refreshToken: $aliceRefresh)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    // An approval whose code the client has not used yet.
    $flow->authorize($this->alice, 'http://localhost/mcp/t/acme')->assertOk();
    $flow->approve()->assertRedirect();

    McpConnection::for($this->alice)->sole()->revoke();

    $flow->token()->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
})->group('database');
