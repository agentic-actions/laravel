<?php

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Client;
use Laravel\Mcp\Enums\ProtocolVersion;
use Tests\Fixtures\Mcp\McpEnvironment;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * laravel/mcp's own client against the mount, in both handshake styles. The client sends through Laravel's Http
 * facade, so a fake replays each request through the HTTP kernel, with the guards forgotten first so each token is
 * really read.
 */

uses(McpEnvironment::class);

beforeEach(function () {
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

    $this->user = User::factory()->create();
});

it('connects, lists and calls tools', function (ProtocolVersion $version) {
    $token = $this->user->createToken('client', ['actions:read', 'actions:write'])->plainTextToken;

    $client = Client::web(url('/mcp/actions'))->withToken($token)->withProtocolVersion($version)->connect();

    expect($client->instructions())->toBe(trans('agentic-actions::mcp.instructions'))
        ->and($client->tools()->map->name->values()->all())->toBe(['mcp-crashes', 'mcp-create-post', 'mcp-keyed', 'mcp-read-posts', 'mcp-translated']);

    $result = $client->callTool('mcp-create-post', ['title' => 'From a client', 'body' => 'x']);

    expect($result->text())->toBe('Done.')
        ->and($result->isError)->toBeFalse()
        ->and(Post::query()->sole()->title)->toBe('From a client');

    $refused = $client->callTool('mcp-keyed');

    expect($refused->isError)->toBeTrue()
        ->and($refused->text())->toBe(trans('agentic-actions::model.idempotency_required'));
})->with([
    'legacy initialize' => ProtocolVersion::V2025_11_25,
    '2026-07-28 discover' => ProtocolVersion::V2026_07_28,
]);
