<?php

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Client;
use Laravel\Mcp\Enums\ProtocolVersion;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\Elicitation\McpAskingTeamPost;
use Tests\Fixtures\Mcp\JsonRpc;
use Tests\Fixtures\Mcp\McpEnvironment;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * Asking over MCP: a 2026-07-28 request whose client declares form elicitation gets an InputRequiredResult for a call
 * missing fields a form can hold, and its retry with the answer and the request state runs the action once, through
 * every check. Raw JSON-RPC bodies, since laravel/mcp's own client declares no capability and never retries; that
 * client gets the refusal naming the fields. It needs no laravel/ai.
 */

uses(McpEnvironment::class);

beforeEach(function () {
    $this->bootMcp(['agentic-actions.discovery.classes' => [McpAskingTeamPost::class]]);

    McpAskingTeamPost::$handled = [];

    $this->user = User::factory()->create();
    $this->acme = Team::factory()->create(['slug' => 'acme']);
    $this->globex = Team::factory()->create(['slug' => 'globex']);
    $this->acme->users()->attach($this->user);
    $this->globex->users()->attach($this->user);
    $this->token = $this->user->createToken('client', ['actions:read', 'actions:write'])->plainTextToken;
});

/**
 * One tools/call of the asking action on a path, from a client that declares form elicitation unless told otherwise.
 *
 * @param  array<string, mixed>  $arguments
 * @param  array<string, mixed>  $retry  inputResponses and requestState
 * @param  array<string, mixed>  $capabilities
 * @return TestResponse<Response>
 */
function mcpAsk(array $arguments, array $retry = [], ?string $token = null, string $path = '/mcp/t/acme', array $capabilities = ['elicitation' => ['form' => []]], string $name = 'mcp-asking-team-post'): TestResponse
{
    [$body, $headers] = JsonRpc::current('tools/call', ['name' => $name, 'arguments' => (object) $arguments, ...$retry], id: random_int(1, 999_999), capabilities: array_map(fn (array $value): object => (object) array_map(fn (array $mode): object => (object) $mode, $value), $capabilities));

    return test()->mcp($path, $body, $token ?? test()->token, $headers);
}

/**
 * The request state of a first call from these arguments.
 *
 * @param  array<string, mixed>  $arguments
 */
function mcpState(array $arguments = ['title' => 'Launch notes'], ?string $token = null, string $path = '/mcp/t/acme'): string
{
    $state = mcpAsk($arguments, token: $token, path: $path)->json('result.requestState');

    expect($state)->toBeString();

    return $state;
}

/**
 * A retry's inputResponses and requestState.
 *
 * @param  array<string, mixed>  $answer
 * @return array<string, mixed>
 */
function mcpAnswer(string $state, array $answer): array
{
    return ['inputResponses' => ['mcp-asking-team-post' => $answer], 'requestState' => $state];
}

/**
 * Expect an InputRequiredResult and that nothing ran.
 */
function expectPaused(TestResponse $response): void
{
    expect($response->status())->toBe(200)
        ->and($response->json('result.resultType'))->toBe('input_required')
        ->and($response->json('result.inputRequests.mcp-asking-team-post.method'))->toBe('elicitation/create')
        ->and($response->json('result.requestState'))->toBeString()
        ->and($response->json('result'))->not->toHaveKey('content')
        ->and(McpAskingTeamPost::$handled)->toBe([])
        ->and(Post::query()->count())->toBe(0);
}

it('answers an incomplete call with one elicitation/create in MCP\'s standard keys and a request state', function (array $capabilities) {
    $response = mcpAsk(['title' => 'Launch notes'], capabilities: $capabilities);

    expectPaused($response);

    expect($response->json('result.inputRequests'))->toHaveCount(1)
        ->and($response->json('result.inputRequests.mcp-asking-team-post.params'))->toEqual([
            'mode' => 'form',
            'message' => 'A few details for your post.',
            'requestedSchema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'title' => 'Title', 'minLength' => 1, 'maxLength' => 120, 'default' => 'Launch notes'],
                    'body' => ['type' => 'string', 'title' => 'Body', 'description' => 'What the post says.', 'maxLength' => 5000],
                    'status' => ['type' => 'string', 'title' => 'Status', 'oneOf' => [['const' => 'draft', 'title' => 'Draft'], ['const' => 'published', 'title' => 'Published']]],
                ],
                'required' => ['title', 'body', 'status'],
            ],
        ])
        ->and($response->getContent())->not->toContain('x-agentic-actions')
        ->and($response->json('result._meta'))->toHaveKey('io.modelcontextprotocol/serverInfo')
        ->and($response->json('error'))->toBeNull();
})->with([
    'elicitation with form' => [['elicitation' => ['form' => []]]],
    'elicitation as {}' => [['elicitation' => []]],
    'form and url' => [['elicitation' => ['form' => [], 'url' => []]]],
]);

it('runs the accepted answer once with the arguments, and names only the filled fields', function () {
    $response = mcpAsk(['title' => 'Launch notes'], mcpAnswer(mcpState(), [
        'action' => 'accept',
        'content' => ['title' => 'Launch notes', 'body' => 'What shipped this week', 'status' => 'draft'],
    ]));

    expect($response->json('result.resultType'))->toBe('complete')
        ->and($response->json('result.isError'))->toBeFalse()
        ->and($response->json('result.content'))->toBe([['type' => 'text', 'text' => 'The person filled in: title, body, status. Done.']])
        ->and(McpAskingTeamPost::$handled)->toBe([['title' => 'Launch notes', 'body' => 'What shipped this week', 'status' => 'draft']])
        ->and(Post::query()->sole()->only(['title', 'team_id', 'user_id']))->toBe(['title' => 'Launch notes', 'team_id' => $this->acme->id, 'user_id' => $this->user->id]);
});

it('runs nothing on decline or cancel', function (string $action, string $sentence) {
    $response = mcpAsk(['title' => 'Launch notes'], mcpAnswer(mcpState(), ['action' => $action]));

    expect($response->json('result.resultType'))->toBe('complete')
        ->and($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toBe($sentence)
        ->and(McpAskingTeamPost::$handled)->toBe([])
        ->and(Post::query()->count())->toBe(0);
})->with([
    'decline' => ['decline', 'The person declined to fill in title, body, status, so nothing ran. Do not ask again unless they ask.'],
    'cancel' => ['cancel', 'The person closed the form without answering, so nothing ran. Offer it again only if it still matters.'],
]);

it('asks again, opening on the accepted values (never the refused one) with the messages, when the action\'s own rules refuse the answer', function () {
    Post::query()->forceCreate(['user_id' => $this->user->id, 'title' => 'Taken', 'body' => 'x', 'status' => 'draft']);
    $state = mcpState();

    $response = mcpAsk(['title' => 'Launch notes'], mcpAnswer($state, [
        'action' => 'accept',
        'content' => ['title' => 'Taken', 'body' => 'What shipped', 'status' => 'published'],
    ]));

    $properties = $response->json('result.inputRequests.mcp-asking-team-post.params.requestedSchema.properties');

    expect($response->json('result.resultType'))->toBe('input_required')
        ->and($response->json('result.requestState'))->toBeString()->not->toBe($state)
        ->and($properties['title']['default'])->toBe('Launch notes')
        ->and($properties['title']['description'])->toBe('The title has already been taken.')
        ->and($properties['body']['default'])->toBe('What shipped')
        ->and($properties['body']['description'])->toBe('What the post says.')
        ->and($properties['status']['default'])->toBe('published')
        ->and(McpAskingTeamPost::$handled)->toBe([]);

    $retry = mcpAsk(['title' => 'Launch notes'], mcpAnswer($response->json('result.requestState'), [
        'action' => 'accept',
        'content' => ['title' => 'Fresh', 'body' => 'What shipped', 'status' => 'published'],
    ]));

    expect($retry->json('result.content.0.text'))->toBe('The person filled in: title, body, status. Done.')
        ->and(McpAskingTeamPost::$handled)->toHaveCount(1);
});

it('asks again when the retry carries no answer under the tool\'s name', function (array $retry) {
    $state = mcpState();

    expectPaused(mcpAsk(['title' => 'Launch notes'], ['requestState' => $state, ...$retry]));
})->with([
    'no inputResponses' => [[]],
    'another key' => [['inputResponses' => ['something-else' => ['action' => 'accept', 'content' => ['title' => 'x']]]]],
]);

it('answers -32602 for an answer that is not an elicitation result, and runs nothing', function (mixed $responses) {
    $response = mcpAsk(['title' => 'Launch notes'], ['inputResponses' => $responses, 'requestState' => mcpState()]);

    expect($response->json('error.code'))->toBe(-32602)
        ->and($response->json('result'))->toBeNull()
        ->and(McpAskingTeamPost::$handled)->toBe([]);
})->with([
    'an unknown action' => [['mcp-asking-team-post' => ['action' => 'maybe']]],
    'no action' => [['mcp-asking-team-post' => ['content' => ['title' => 'x']]]],
    'content as a list' => [['mcp-asking-team-post' => ['action' => 'accept', 'content' => ['x', 'y']]]],
    'content as a string' => [['mcp-asking-team-post' => ['action' => 'accept', 'content' => 'x']]],
    'an entry that is a string' => [['mcp-asking-team-post' => 'accept']],
    'inputResponses as a string' => ['accept'],
]);

it('answers -32602 for a request state that does not verify, and runs nothing', function (Closure $state) {
    $response = mcpAsk(['title' => 'Launch notes'], mcpAnswer($state(mcpState()), [
        'action' => 'accept',
        'content' => ['title' => 'Launch notes', 'body' => 'x', 'status' => 'draft'],
    ]));

    expect($response->json('error.code'))->toBe(-32602)
        ->and($response->json('error.message'))->toBe('Invalid params: the [requestState] does not verify.')
        ->and(McpAskingTeamPost::$handled)->toBe([])
        ->and(Post::query()->count())->toBe(0);
})->with([
    'one byte altered' => [fn (string $state): string => substr($state, 0, 40).($state[40] === 'A' ? 'B' : 'A').substr($state, 41)],
    'not a string' => [fn (string $state): int => 42],
    'not encrypted' => [fn (string $state): string => base64_encode('{"v":1}')],
    'another payload of the app' => [fn (string $state): string => Crypt::encryptString('{"v":1}')],
    'a binding that is a list' => [fn (string $state): string => Crypt::encryptString(json_encode(['v' => 1, 'p' => 'agentic-actions.mcp-form', 'b' => ['x'], 'e' => now()->addHour()->getTimestamp()]))],
    'not JSON' => [fn (string $state): string => Crypt::encryptString('not json')],
    'another purpose' => [fn (string $state): string => Crypt::encryptString(json_encode(['v' => 1, 'p' => 'other', 'b' => 'x', 'e' => now()->addHour()->getTimestamp()]))],
]);

it('asks afresh, running nothing, when the state was minted for another token, person or tenant, or other arguments', function (Closure $retry) {
    $state = mcpState();

    expectPaused($retry->call($this, $state, ['action' => 'accept', 'content' => ['title' => 'Launch notes', 'body' => 'x', 'status' => 'draft']]));
})->with([
    'another token of the same person' => [function (string $state, array $answer): TestResponse {
        return mcpAsk(['title' => 'Launch notes'], mcpAnswer($state, $answer), $this->user->createToken('other', ['actions:read', 'actions:write'])->plainTextToken);
    }],
    'another person' => [function (string $state, array $answer): TestResponse {
        $teammate = User::factory()->create();
        $this->acme->users()->attach($teammate);

        return mcpAsk(['title' => 'Launch notes'], mcpAnswer($state, $answer), $teammate->createToken('client', ['actions:read', 'actions:write'])->plainTextToken);
    }],
    'another tenant' => [fn (string $state, array $answer): TestResponse => mcpAsk(['title' => 'Launch notes'], mcpAnswer($state, $answer), path: '/mcp/t/globex')],
    'other arguments' => [fn (string $state, array $answer): TestResponse => mcpAsk(['title' => 'Other notes'], mcpAnswer($state, $answer))],
    'arguments with an extra key' => [fn (string $state, array $answer): TestResponse => mcpAsk(['title' => 'Launch notes', 'user_id' => 99], mcpAnswer($state, $answer))],
]);

it('keeps only the form\'s fields from an answer with extra keys, and ignores other entries', function () {
    $response = mcpAsk(['title' => 'Launch notes'], [
        'inputResponses' => [
            'mcp-asking-team-post' => ['action' => 'accept', 'content' => ['title' => 'Launch notes', 'body' => 'x', 'status' => 'draft', 'user_id' => 99, 'team_id' => $this->globex->id], 'extra' => true],
            'something-else' => ['action' => 'decline'],
        ],
        'requestState' => mcpState(),
    ]);

    expect($response->json('result.content.0.text'))->toBe('The person filled in: title, body, status. Done.')
        ->and(McpAskingTeamPost::$handled)->toBe([['title' => 'Launch notes', 'body' => 'x', 'status' => 'draft']])
        ->and(Post::query()->sole()->only(['team_id', 'user_id']))->toBe(['team_id' => $this->acme->id, 'user_id' => $this->user->id]);
});

it('runs a retry whose arguments are now complete as a direct call, ignoring the answer', function () {
    $response = mcpAsk(['title' => 'Complete', 'body' => 'x', 'status' => 'draft'], mcpAnswer(mcpState(), [
        'action' => 'accept',
        'content' => ['title' => 'From the form', 'body' => 'y', 'status' => 'published'],
    ]));

    expect($response->json('result.content.0.text'))->toBe('Done.')
        ->and(McpAskingTeamPost::$handled)->toBe([['title' => 'Complete', 'body' => 'x', 'status' => 'draft']]);
});

it('gives laravel/mcp\'s own client, which declares no elicitation, the refusal naming the fields', function () {
    $test = $this;

    Http::fake(function (ClientRequest $request) use ($test) {
        $test->app['auth']->forgetGuards();

        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($request->headers() as $name => $values) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = implode(', ', $values);
        }

        $response = $test->call($request->method(), (string) parse_url($request->url(), PHP_URL_PATH), server: $server, content: $request->body());

        return Http::response($response->getContent(), $response->status(), $response->headers->all());
    });

    $client = Client::web(url('/mcp/t/acme'))->withToken($this->token)->withProtocolVersion(ProtocolVersion::V2026_07_28)->connect();
    $result = $client->callTool('mcp-asking-team-post', ['title' => 'Launch notes']);

    expect($result->isError)->toBeTrue()
        ->and($result->text())->toBe('Not done. Rejected: body (required), status (required).')
        ->and(McpAskingTeamPost::$handled)->toBe([]);
});
