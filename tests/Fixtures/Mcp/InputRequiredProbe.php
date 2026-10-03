<?php

namespace Tests\Fixtures\Mcp;

use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * A tools/call handler that answers with its own resultType, as CallAction does for a form.
 */
final class InputRequiredProbe implements Method
{
    /**
     * An InputRequiredResult holding only a request state.
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        return JsonRpcResponse::result($request->id, ['resultType' => 'input_required', 'requestState' => 'probe']);
    }
}
