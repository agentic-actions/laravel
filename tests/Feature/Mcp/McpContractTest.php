<?php

use AgenticActions\Mcp\CallAction;
use Laravel\Mcp\Enums\ErrorCode;
use Laravel\Mcp\Enums\MetaKey;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\ToolInvoker;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Tests\Fixtures\Mcp\CapturingTransport;
use Tests\Fixtures\Mcp\InputRequiredProbe;

/*
 * The laravel/mcp members CallAction relies on. A laravel/mcp release that changes one fails here
 * before an MCP client notices.
 */

it('lets a server replace tools/call, and keeps the result type its handler gives', function () {
    $transport = new CapturingTransport;
    $server = new class($transport) extends Server
    {
        protected function boot(): void
        {
            $this->addMethod('tools/call', InputRequiredProbe::class);
        }
    };

    $server->start();
    $transport->receive((string) json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => [
        'name' => 'anything',
        '_meta' => [MetaKey::PROTOCOL_VERSION->value => '2026-07-28', MetaKey::CLIENT_CAPABILITIES->value => ['elicitation' => (object) []]],
    ]]));

    $sent = json_decode($transport->sent[0], true);

    expect($transport->sent)->toHaveCount(1)
        ->and($sent['id'])->toBe(7)
        ->and($sent['result']['resultType'])->toBe('input_required')
        ->and($sent['result']['requestState'])->toBe('probe')
        ->and($sent['result']['_meta'])->toHaveKey(MetaKey::SERVER_INFO->value);
});

it('keeps the members CallAction extends and calls', function () {
    $handle = new ReflectionMethod(CallTool::class, 'handle');

    expect((new ReflectionClass(CallTool::class))->isFinal())->toBeFalse()
        ->and(get_parent_class(CallAction::class))->toBe(CallTool::class)
        ->and(array_map(fn (ReflectionParameter $parameter): string => (string) $parameter->getType(), $handle->getParameters()))->toBe([JsonRpcRequest::class, ServerContext::class])
        ->and((new ReflectionMethod(Server::class, 'addMethod'))->isPublic())->toBeTrue()
        ->and((string) (new ReflectionMethod(ToolInvoker::class, 'invoke'))->getReturnType())->toBe('Generator|'.JsonRpcResponse::class)
        ->and((new ReflectionMethod(ServerContext::class, 'tools'))->isPublic())->toBeTrue()
        ->and(MetaKey::CLIENT_CAPABILITIES->value)->toBe('io.modelcontextprotocol/clientCapabilities')
        ->and(ErrorCode::INVALID_PARAMS->value)->toBe(-32602)
        ->and(JsonRpcResponse::result(3, ['resultType' => 'input_required'])->toArray())->toBe(['jsonrpc' => '2.0', 'id' => 3, 'result' => ['resultType' => 'input_required']]);
});

it('reads a request\'s meta, legacy status and retry params as CallAction does', function () {
    $capabilities = [MetaKey::CLIENT_CAPABILITIES->value => ['elicitation' => ['form' => []]]];
    $retry = new JsonRpcRequest(2, 'tools/call', ['name' => 'x', 'arguments' => ['a' => 1], 'inputResponses' => ['x' => []], 'requestState' => 's', '_meta' => $capabilities]);

    expect($retry->isLegacy())->toBeFalse()
        ->and($retry->meta())->toBe($capabilities)
        ->and($retry->get('requestState'))->toBe('s')
        ->and($retry->get('inputResponses'))->toBe(['x' => []])
        ->and($retry->toRequest()->all())->toBe(['a' => 1])
        ->and((new JsonRpcRequest(1, 'tools/call', ['_meta' => ['progressToken' => 'p']]))->isLegacy())->toBeTrue();
});
