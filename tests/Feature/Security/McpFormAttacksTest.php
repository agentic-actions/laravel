<?php

use AgenticActions\Contracts\ReadsTokenGrants;
use AgenticActions\Refusal;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Enums\MetaKey;
use Laravel\Mcp\Enums\ProtocolVersion;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\Elicitation\AskingRequired;
use Tests\Fixtures\Elicitation\McpAskingTeamPost;
use Tests\Fixtures\Mcp\JsonRpc;
use Tests\Fixtures\Mcp\McpEnvironment;
use Tests\Fixtures\McpFormAttacks\HiddenRequirement;
use Tests\Fixtures\McpFormAttacks\PurgeTeamNotes;
use Tests\Fixtures\McpFormAttacks\SealTeamPost;
use Tests\Fixtures\McpFormAttacks\TeamNote;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * The 0.6 security review of asking over MCP and of requiredForAgents() there. A client holding a valid token, a
 * prompt-injected model writing its arguments and a second member try to forge, replay, swap or stretch a request
 * state, to answer without one or for another tool, to make a client without the capability take the form path, to
 * make a Destructive action ask or run, to get a value the person typed back to the model, and to spend the server's
 * work past the throttle. Raw JSON-RPC bodies; no laravel/ai.
 */

uses(McpEnvironment::class);

beforeEach(function () {
    $this->bootMcp(['agentic-actions.discovery.classes' => [McpAskingTeamPost::class, TeamNote::class, PurgeTeamNotes::class, SealTeamPost::class, AskingRequired::class, HiddenRequirement::class]]);

    McpAskingTeamPost::$handled = TeamNote::$handled = [];
    TeamNote::$purge = AskingRequired::$handled = null;
    PurgeTeamNotes::$handled = SealTeamPost::$handled = HiddenRequirement::$handled = false;
    HiddenRequirement::$summaryRules = 'sometimes|max:200';

    $this->user = User::factory()->create();
    $this->acme = Team::factory()->create(['slug' => 'acme']);
    $this->acme->users()->attach($this->user);
    $this->token = $this->user->createToken('client', ['actions:read', 'actions:write'])->plainTextToken;
});

/**
 * One 2026-07-28 tools/call, from a client that declares form elicitation unless told otherwise.
 *
 * @param  array<string, mixed>  $params  arguments, and the retry's inputResponses and requestState
 * @param  array<string, mixed>|null  $capabilities
 * @return TestResponse<Response>
 */
function formAttackCall(string $name, array $params, ?string $token = null, string $path = '/mcp/t/acme', ?array $capabilities = ['elicitation' => ['form' => []]], array $headers = []): TestResponse
{
    $params['arguments'] = (object) ($params['arguments'] ?? []);
    [$body, $mirrored] = JsonRpc::current('tools/call', ['name' => $name, ...$params], id: random_int(1, 999_999), capabilities: array_map(fn (array $value): object => (object) array_map(fn (array $mode): object => (object) $mode, $value), $capabilities ?? []));

    return test()->mcp($path, $body, $token ?? test()->token, [...$mirrored, ...$headers]);
}

/**
 * The request state McpAskingTeamPost's first call gets for a title.
 */
function formAttackState(string $title = 'Launch notes', ?string $token = null, array $headers = []): string
{
    $state = formAttackCall('mcp-asking-team-post', ['arguments' => ['title' => $title]], $token, headers: $headers)->json('result.requestState');

    expect($state)->toBeString();

    return $state;
}

/**
 * A complete accept, keyed by a tool's name.
 *
 * @return array<string, mixed>
 */
function formAttackAccept(string $name, array $content = ['title' => 'Launch notes', 'body' => 'What shipped', 'status' => 'draft']): array
{
    return [$name => ['action' => 'accept', 'content' => $content]];
}

/**
 * Expect an InputRequiredResult for the tool, and that nothing ran anywhere.
 */
function expectFormAttackPaused(TestResponse $response, string $name = 'mcp-asking-team-post'): void
{
    expect($response->json('result.resultType'))->toBe('input_required')
        ->and($response->json("result.inputRequests.{$name}.method"))->toBe('elicitation/create')
        ->and(McpAskingTeamPost::$handled)->toBe([])
        ->and(TeamNote::$handled)->toBe([])
        ->and(Post::query()->count())->toBe(0);
}

it('never lets one tool\'s state answer another tool, even one whose form is the same form', function () {
    $state = formAttackState();

    $theirs = formAttackCall('team-note', ['arguments' => ['title' => 'Launch notes']]);

    expect($theirs->json('result.inputRequests.team-note.params'))
        ->toBe(formAttackCall('mcp-asking-team-post', ['arguments' => ['title' => 'Launch notes']])->json('result.inputRequests.mcp-asking-team-post.params'));

    $response = formAttackCall('team-note', [
        'arguments' => ['title' => 'Launch notes'],
        'inputResponses' => formAttackAccept('team-note'),
        'requestState' => $state,
    ]);

    expectFormAttackPaused($response, 'team-note');

    expect($response->json('result.requestState'))->not->toBe($state);
});

it('never runs an answer that comes without a request state', function (array $state) {
    expectFormAttackPaused(formAttackCall('mcp-asking-team-post', [
        'arguments' => ['title' => 'Launch notes'],
        'inputResponses' => formAttackAccept('mcp-asking-team-post'),
        ...$state,
    ]));
})->with([
    'no requestState' => [[]],
    'a null requestState' => [['requestState' => null]],
]);

it('gives a retry whose client no longer declares form elicitation the refusal, and ignores its state and answer', function (?array $capabilities) {
    $response = formAttackCall('mcp-asking-team-post', [
        'arguments' => ['title' => 'Launch notes'],
        'inputResponses' => formAttackAccept('mcp-asking-team-post'),
        'requestState' => formAttackState(),
    ], capabilities: $capabilities);

    expect($response->json('result.resultType'))->toBe('complete')
        ->and($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toBe('Not done. Rejected: body (required), status (required).')
        ->and(McpAskingTeamPost::$handled)->toBe([]);
})->with([
    'no capabilities' => [null],
    'url only' => [['elicitation' => ['url' => []]]],
    'sampling only' => [['sampling' => []]],
]);

it('reads the capability, the state and the answer from the request, never from the arguments a model writes', function () {
    $state = formAttackState();
    $smuggled = [
        'title' => 'Launch notes',
        'requestState' => $state,
        'inputResponses' => formAttackAccept('mcp-asking-team-post'),
        '_meta' => [MetaKey::CLIENT_CAPABILITIES->value => ['elicitation' => ['form' => []]]],
    ];

    $refused = formAttackCall('mcp-asking-team-post', ['arguments' => $smuggled], capabilities: null);

    expect($refused->json('result.content.0.text'))->toBe('Not done. Rejected: body (required), status (required).');

    expectFormAttackPaused(formAttackCall('mcp-asking-team-post', ['arguments' => $smuggled]));
});

it('takes no capability from a request that is not on 2026-07-28', function (array $meta) {
    $body = JsonRpc::legacy('tools/call', [
        'name' => 'mcp-asking-team-post',
        'arguments' => ['title' => 'Launch notes'],
        '_meta' => $meta,
    ]);

    $response = $this->mcp('/mcp/t/acme', $body, $this->token);

    expect($response->json('result.resultType'))->not->toBe('input_required')
        ->and($response->getContent())->not->toContain('elicitation/create')
        ->and(McpAskingTeamPost::$handled)->toBe([]);
})->with([
    'capabilities without a protocol version' => [[MetaKey::CLIENT_CAPABILITIES->value => ['elicitation' => ['form' => (object) []]]]],
    'an older protocol version' => [[MetaKey::PROTOCOL_VERSION->value => ProtocolVersion::V2025_11_25->value, MetaKey::CLIENT_CAPABILITIES->value => ['elicitation' => ['form' => (object) []]]]],
    'elicitation that is not an object' => [[MetaKey::PROTOCOL_VERSION->value => ProtocolVersion::V2026_07_28->value, MetaKey::CLIENT_CAPABILITIES->value => ['elicitation' => 'form']]],
]);

it('never lists, asks for or runs a Destructive action that declares $askForMissing, whatever the token or the state', function () {
    $destructive = $this->user->createToken('all', ['actions:read', 'actions:write', 'actions:destructive'])->plainTextToken;
    [$list, $headers] = JsonRpc::current('tools/list');

    expect(collect($this->mcp('/mcp/t/acme', $list, $destructive, $headers)->json('result.tools'))->pluck('name')->all())
        ->toContain('mcp-asking-team-post')
        ->not->toContain('purge-team-notes');

    foreach ([[], ['inputResponses' => [...formAttackAccept('purge-team-notes', ['before' => '2026-01-01'])], 'requestState' => formAttackState(token: $destructive)]] as $retry) {
        expect(formAttackCall('purge-team-notes', ['arguments' => [], ...$retry], $destructive)->json('error.message'))
            ->toBe('Tool [purge-team-notes] not found.');
    }

    expect(PurgeTeamNotes::$handled)->toBeFalse();
});

it('runs no Destructive action in-process from a form\'s run, even for a token that holds the destructive ability', function () {
    $destructive = $this->user->createToken('all', ['actions:read', 'actions:write', 'actions:destructive'])->plainTextToken;
    $state = formAttackCall('team-note', ['arguments' => ['title' => 'Launch notes']], $destructive)->json('result.requestState');

    $response = formAttackCall('team-note', [
        'arguments' => ['title' => 'Launch notes'],
        'inputResponses' => formAttackAccept('team-note', ['title' => 'Launch notes', 'body' => 'purge', 'status' => 'draft']),
        'requestState' => $state,
    ], $destructive);

    expect($response->json('result.isError'))->toBeFalse()
        ->and(TeamNote::$handled)->toHaveCount(1)
        ->and(TeamNote::$purge)->toBe(Refusal::class)
        ->and(PurgeTeamNotes::$handled)->toBeFalse();
});

it('keeps a message handle() raises quoting the person\'s value off the model on a form\'s run, though the action sends messages to a model', function () {
    $direct = formAttackCall('team-note', ['arguments' => ['title' => 'Canary direct', 'body' => 'taken', 'status' => 'draft']]);

    expect($direct->json('result.content.0.text'))->toContain('Canary direct');

    $state = formAttackCall('team-note', ['arguments' => ['title' => 'Launch notes']])->json('result.requestState');
    $response = formAttackCall('team-note', [
        'arguments' => ['title' => 'Launch notes'],
        'inputResponses' => formAttackAccept('team-note', ['title' => 'Canary typed', 'body' => 'taken', 'status' => 'draft']),
        'requestState' => $state,
    ]);

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toBe('The person filled in: title, body, status. Not done. Rejected: title.')
        ->and($response->getContent())->not->toContain('Canary typed');
});

it('puts no value of an answer into an error, a decline or a fresh form', function () {
    $canary = ['title' => 'Canary title', 'body' => 'Canary body', 'status' => 'published'];

    $malformed = formAttackCall('mcp-asking-team-post', [
        'arguments' => ['title' => 'Launch notes'],
        'inputResponses' => ['mcp-asking-team-post' => ['action' => 'maybe', 'content' => $canary]],
        'requestState' => formAttackState(),
    ]);
    $declined = formAttackCall('mcp-asking-team-post', [
        'arguments' => ['title' => 'Launch notes'],
        'inputResponses' => ['mcp-asking-team-post' => ['action' => 'decline', 'content' => $canary]],
        'requestState' => formAttackState(),
    ]);

    $state = formAttackState();
    $this->travel(1801)->seconds();
    $expired = formAttackCall('mcp-asking-team-post', [
        'arguments' => ['title' => 'Launch notes'],
        'inputResponses' => formAttackAccept('mcp-asking-team-post', $canary),
        'requestState' => $state,
    ]);

    expect($malformed->json('error.code'))->toBe(-32602)
        ->and($declined->json('result.isError'))->toBeTrue();

    expectFormAttackPaused($expired);

    foreach ([$malformed, $declined, $expired] as $response) {
        expect($response->getContent())->not->toContain('Canary');
    }
});

it('re-checks membership and the token on a retry: a state outlives neither', function () {
    $state = formAttackState();
    $this->acme->users()->detach($this->user);

    expect(formAttackCall('mcp-asking-team-post', [
        'arguments' => ['title' => 'Launch notes'],
        'inputResponses' => formAttackAccept('mcp-asking-team-post'),
        'requestState' => $state,
    ])->json('error.message'))->toBe('Tool [mcp-asking-team-post] not found.');

    $this->acme->users()->attach($this->user);
    $state = formAttackState();
    $this->user->tokens()->delete();

    expect(formAttackCall('mcp-asking-team-post', [
        'arguments' => ['title' => 'Launch notes'],
        'inputResponses' => formAttackAccept('mcp-asking-team-post'),
        'requestState' => $state,
    ])->status())->toBe(401)
        ->and(McpAskingTeamPost::$handled)->toBe([]);
});

it('counts every retry and every replay against the person\'s throttle', function () {
    $this->bootMcp([
        'agentic-actions.discovery.classes' => [McpAskingTeamPost::class],
        'agentic-actions.mcp.per_minute' => 2,
    ]);
    $this->user = User::factory()->create();
    Team::factory()->create(['slug' => 'acme'])->users()->attach($this->user);
    $this->token = $this->user->createToken('client', ['actions:read', 'actions:write'])->plainTextToken;

    $retry = ['arguments' => ['title' => 'Launch notes'], 'inputResponses' => formAttackAccept('mcp-asking-team-post'), 'requestState' => formAttackState()];

    expect(formAttackCall('mcp-asking-team-post', $retry)->json('result.isError'))->toBeFalse()
        ->and(formAttackCall('mcp-asking-team-post', $retry)->status())->toBe(429)
        ->and(McpAskingTeamPost::$handled)->toHaveCount(1);
});

it('runs nothing from a batch of retries in one body', function () {
    $state = formAttackState();
    [$retry, $headers] = JsonRpc::current('tools/call', [
        'name' => 'mcp-asking-team-post',
        'arguments' => ['title' => 'Launch notes'],
        'inputResponses' => formAttackAccept('mcp-asking-team-post'),
        'requestState' => $state,
    ], capabilities: ['elicitation' => (object) ['form' => (object) []]]);

    $response = $this->mcp('/mcp/t/acme', [$retry, [...$retry, 'id' => 2]], $this->token, $headers);

    expect($response->getContent())->not->toContain('"resultType":"complete"')
        ->and(McpAskingTeamPost::$handled)->toBe([]);
});

it('asks again, running nothing, for an answer that leaves a field requiredForAgents() names empty or null', function () {
    $first = formAttackCall('asking-required', ['arguments' => ['title' => 'Launch notes']], path: '/mcp/actions');

    expect($first->json('result.inputRequests.asking-required.params.requestedSchema.required'))->toBe(['publish_on', 'status']);

    $response = formAttackCall('asking-required', [
        'arguments' => ['title' => 'Launch notes'],
        'inputResponses' => ['asking-required' => ['action' => 'accept', 'content' => ['publish_on' => '', 'status' => null]]],
        'requestState' => $first->json('result.requestState'),
    ], path: '/mcp/actions');

    expect($response->json('result.resultType'))->toBe('input_required')
        ->and(AskingRequired::$handled)->toBeNull();

    $done = formAttackCall('asking-required', [
        'arguments' => ['title' => 'Launch notes'],
        'inputResponses' => ['asking-required' => ['action' => 'accept', 'content' => ['publish_on' => '2026-10-01', 'status' => 'draft']]],
        'requestState' => $response->json('result.requestState'),
    ], path: '/mcp/actions');

    expect($done->json('result.content.0.text'))->toBe('The person filled in: publish_on, status. Done.')
        ->and(AskingRequired::$handled)->toBe(['title' => 'Launch notes', 'publish_on' => '2026-10-01', 'status' => 'draft']);
});

it('never opens a key agents are not offered because requiredForAgents() names it, to a model or a form', function () {
    [$list, $headers] = JsonRpc::current('tools/list');
    $tool = collect($this->mcp('/mcp/actions', $list, $this->token, $headers)->json('result.tools'))->firstWhere('name', 'hidden-requirement');

    expect(array_keys($tool['inputSchema']['properties']))->toBe(['title', 'summary'])
        ->and($tool['inputSchema']['required'])->toBe(['title', 'summary']);

    $response = formAttackCall('hidden-requirement', ['arguments' => ['title' => 'Launch notes', 'summary' => 'What shipped', 'reviewed' => true]], path: '/mcp/actions');

    expect($response->json('result.resultType'))->toBe('complete')
        ->and($response->json('result.content.0.text'))->toBe('Not done. Rejected: reviewed (required).')
        ->and(HiddenRequirement::$handled)->toBeFalse();
});

it('lets no `sometimes` in rules() excuse a model\'s call from a field requiredForAgents() names, however Laravel reads it', function (mixed $rules) {
    HiddenRequirement::$summaryRules = $rules;

    $response = formAttackCall('hidden-requirement', ['arguments' => ['title' => 'Launch notes']], path: '/mcp/actions', capabilities: null);

    expect($response->json('result.content.0.text'))->toBe('Not done. Rejected: summary (required), reviewed (required).')
        ->and(HiddenRequirement::$handled)->toBeFalse();
})->with([
    'a string' => ['sometimes|max:200'],
    'a list' => [['sometimes', 'max:200']],
    'capitalised, with spaces' => [[' Sometimes ', 'max:200']],
    'an array-form rule' => [[['sometimes'], 'max:200']],
    'inside Rule::when()' => [fn () => [Rule::when(true, ['sometimes']), 'max:200']],
]);

it('runs a replayed state through every check again: once the first run takes the title, the replay gets a fresh form and runs nothing', function () {
    $retry = ['arguments' => ['title' => 'Launch notes'], 'inputResponses' => formAttackAccept('mcp-asking-team-post'), 'requestState' => formAttackState()];

    expect(formAttackCall('mcp-asking-team-post', $retry)->json('result.isError'))->toBeFalse();

    $replay = formAttackCall('mcp-asking-team-post', $retry);

    expect($replay->json('result.resultType'))->toBe('input_required')
        ->and($replay->json('result.requestState'))->not->toBe($retry['requestState'])
        ->and($replay->json('result.inputRequests.mcp-asking-team-post.params.requestedSchema.properties.title'))->not->toHaveKey('default')
        ->and(McpAskingTeamPost::$handled)->toHaveCount(1)
        ->and(Post::query()->count())->toBe(1);
});

it('never asks an MCP client for a secret that agents are offered: the call is refused naming it', function () {
    $response = formAttackCall('seal-team-post', ['arguments' => ['title' => 'Launch notes']]);

    expect($response->json('result.resultType'))->toBe('complete')
        ->and($response->json('result.content.0.text'))->toBe('Not done. Rejected: pass_phrase (required).')
        ->and($response->getContent())->not->toContain('elicitation/create')
        ->and(SealTeamPost::$handled)->toBeFalse();
});

it('binds the credential of any guard that reads a bearer token, and the person alone for one that does not', function (bool $bearer) {
    $this->bootMcp([
        'agentic-actions.discovery.classes' => [McpAskingTeamPost::class],
        'auth.guards.api-key' => ['driver' => 'api-key'],
        'agentic-actions.mcp.middleware' => ['auth:api-key', 'throttle:agentic-actions-mcp'],
    ]);

    $alice = User::factory()->create();
    $bob = User::factory()->create();
    Team::factory()->create(['slug' => 'acme'])->users()->attach([$alice->getKey(), $bob->getKey()]);

    $this->app->instance(ReadsTokenGrants::class, new class implements ReadsTokenGrants
    {
        /**
         * Every credential reads and writes.
         *
         * @return list<string>
         */
        public function grants(?Authenticatable $actor, Guard $guard): ?array
        {
            return ['actions:read', 'actions:write'];
        }
    });

    Auth::viaRequest('api-key', fn (Request $request): ?User => match ($bearer ? $request->bearerToken() : $request->header('X-Api-Key')) {
        'alice-laptop', 'alice-phone' => $alice,
        'bob' => $bob,
        default => null,
    });

    $this->token = 'unused';
    $as = fn (string $key): array => $bearer ? ['token' => $key] : ['token' => 'unused', 'headers' => ['X-Api-Key' => $key]];
    $retry = ['arguments' => ['title' => 'Launch notes'], 'inputResponses' => formAttackAccept('mcp-asking-team-post'), 'requestState' => formAttackState(...$as('alice-laptop'))];

    expectFormAttackPaused(formAttackCall('mcp-asking-team-post', $retry, ...$as('bob')));

    $phone = formAttackCall('mcp-asking-team-post', $retry, ...$as('alice-phone'));

    if ($bearer) {
        expectFormAttackPaused($phone);

        $phone = formAttackCall('mcp-asking-team-post', $retry, ...$as('alice-laptop'));
    }

    expect($phone->json('result.isError'))->toBeFalse()
        ->and(Post::query()->sole()->user_id)->toBe($alice->getKey());
})->with([
    'a bearer token read by the app\'s own guard' => [true],
    'a key in another header' => [false],
]);
