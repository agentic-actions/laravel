<?php

namespace Tests\Feature\Security;

use AgenticActions\Facades\Actions;
use AgenticActions\Feed\ChangeFeed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Feed\Fixtures\FeedDraftPost;
use Tests\Feature\Feed\Fixtures\FeedTeamPost;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * A hostile member, a hostile outsider and a hostile token on the change feed. A poll reads its own person's bucket
 * and, after membership, its own tenant's; it answers every refusal alike, so it tells no one which tenants exist;
 * and it carries keys and a cursor, never an id or a value.
 */

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    config(['agentic-actions.discovery.classes' => [FeedDraftPost::class, FeedTeamPost::class]]);
    $this->useTeamTenancy();

    $this->freezeTime();

    $this->mountRoutes(function () {
        Route::middleware(['web', 'auth'])->group(fn () => Actions::routes(tenant: false));
        Route::middleware(['web', 'auth'])->prefix('teams/{team}')->name('teams.')->group(fn () => Actions::routes(tenant: true));
        Route::middleware('auth:sanctum')->prefix('api/teams/{team}')->name('api.teams.')->group(fn () => Actions::routes(tenant: true));
        Route::middleware('web')->prefix('open/teams/{team}')->name('open.teams.')->group(fn () => Actions::routes(tenant: true));
    });

    $this->member = User::factory()->create();
    $this->outsider = User::factory()->create();
    $this->acme = Team::factory()->create(['slug' => 'acme']);
    $this->globex = Team::factory()->create(['slug' => 'globex']);
    $this->acme->users()->attach($this->member);
    $this->globex->users()->attach($this->outsider);

    $this->since = (int) now()->getTimestampMs() - 1000;
});

/**
 * A poll as the given person, signed in with a session.
 *
 * @return TestResponse<Response>
 */
function hostilePoll(User $user, string $path, mixed $since): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withoutToken()->actingAs($user, 'web')->postJson($path, ['since' => $since]);
}

it('never reads another tenant\'s bucket, whatever the segment\'s case or encoding', function () {
    $this->actingAs($this->outsider)->postJson('/teams/globex/actions/feed-team-post', ['title' => 'Globex plan'])->assertOk();

    foreach (['globex', 'GLOBEX', 'Globex', '%67lobex', 'globex%20', 'globex.', rawurlencode("gl\u{043E}bex")] as $segment) {
        $response = hostilePoll($this->member, "/teams/{$segment}/actions/_changes", $this->since);

        expect($response->status())->toBe(404)
            ->and($response->json('touches'))->toBeNull();
    }

    hostilePoll($this->member, '/teams/acme/actions/_changes', $this->since)->assertOk()->assertJsonPath('touches', []);
});

it('never reads another person\'s own bucket, on an account group or a tenant group', function () {
    $this->actingAs($this->outsider)->postJson('/actions/feed-draft-post', ['title' => 'Private draft'])->assertOk();

    hostilePoll($this->member, '/actions/_changes', $this->since)->assertOk()->assertJsonPath('touches', []);
    hostilePoll($this->member, '/teams/acme/actions/_changes', $this->since)->assertOk()->assertJsonPath('touches', []);
    hostilePoll($this->outsider, '/actions/_changes', $this->since)->assertOk()->assertJsonPath('touches', ['posts']);
});

it('answers an unknown tenant, a foreign one, a guest, a token, even one bound to the tenant with every grant, and a switched-off feed with the same status, headers and body', function () {
    $token = $this->member->createToken('client', ['actions:read'])->plainTextToken;
    $bound = $this->member->createToken('bound', ['*', 'actions:read', 'actions:write', 'tenant:'.$this->acme->id])->plainTextToken;

    $answers = [
        'unknown' => hostilePoll($this->member, '/teams/no-such-team/actions/_changes', null),
        'foreign' => hostilePoll($this->member, '/teams/globex/actions/_changes', null),
    ];

    app('auth')->forgetGuards();
    $answers['guest'] = $this->withoutToken()->postJson('/open/teams/acme/actions/_changes', ['since' => null]);
    $answers['guest, unknown tenant'] = $this->withoutToken()->postJson('/open/teams/no-such-team/actions/_changes', ['since' => null]);

    app('auth')->forgetGuards();
    $answers['token'] = $this->withToken($token)->postJson('/api/teams/acme/actions/_changes', ['since' => null]);

    app('auth')->forgetGuards();
    $answers['token bound to the tenant, with every grant'] = $this->withToken($bound)->postJson('/api/teams/acme/actions/_changes', ['since' => null]);

    config(['agentic-actions.feed.enabled' => false]);
    $answers['off'] = hostilePoll($this->member, '/teams/acme/actions/_changes', null);

    $shape = fn (TestResponse $response): array => [
        $response->status(),
        $response->headers->get('Content-Type'),
        $response->headers->get('Cache-Control'),
        $response->getContent(),
    ];

    expect(array_unique(array_map(fn (TestResponse $response): string => serialize($shape($response)), $answers)))->toHaveCount(1)
        ->and($answers['unknown']->status())->toBe(404);
});

it('carries keys and a cursor only, and keeps nothing but times, whatever a write held', function () {
    config(['cache.default' => 'database']);

    $this->acme->users()->attach($this->outsider);
    $this->actingAs($this->outsider)->postJson('/teams/acme/actions/feed-team-post', ['title' => 'Secret Merger Plan'])->assertOk();

    $body = hostilePoll($this->member, '/teams/acme/actions/_changes', $this->since)->assertOk()->json();

    expect(array_keys($body))->toBe(['now', 'touches'])
        ->and($body['now'])->toBeInt()
        ->and($body['touches'])->toBe(['posts'])
        ->and(json_encode($body['touches']))->not->toContain('Secret')->not->toContain((string) $this->outsider->id);

    $entries = DB::table('cache')->where('key', 'like', '%'.ChangeFeed::PREFIX.'%')->get(['key', 'value']);

    expect($entries)->not->toBeEmpty();

    foreach ($entries as $entry) {
        $value = unserialize($entry->value);

        expect($value)->toBeInt()
            ->and($entry->key.$entry->value)->not->toContain('Secret');
    }
});

it('answers every hostile cursor with declared keys at most, and never an error', function () {
    $this->actingAs($this->member)->postJson('/teams/acme/actions/feed-team-post', ['title' => 'x'])->assertOk();

    foreach ([PHP_INT_MAX, PHP_INT_MIN, -1, 0, 1e30, '99999999999999999999999', ['since' => 1], true, 'posts', '*'] as $since) {
        $touches = hostilePoll($this->member, '/teams/acme/actions/_changes', $since)->assertOk()->json('touches');

        expect(array_diff($touches, ['posts', '*']))->toBe([]);
    }
});
