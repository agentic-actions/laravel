<?php

namespace Tests\Fixtures\Mcp;

use Laravel\Mcp\Enums\MetaKey;
use Laravel\Mcp\Enums\ProtocolVersion;
use Laravel\Mcp\Enums\RequestHeader;
use Laravel\Mcp\Transport\JsonRpcRequest;

/**
 * JSON-RPC bodies for the MCP tests, in both handshake styles.
 */
final class JsonRpc
{
    /**
     * A legacy request: no _meta.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function legacy(string $method, array $params = [], int|string $id = 1): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, ...($params === [] ? [] : ['params' => $params])];
    }

    /**
     * A 2026-07-28 request, with the protocol version and client capabilities in _meta, and the headers laravel/mcp's
     * own client mirrors: MCP-Protocol-Version, Mcp-Method, and Mcp-Name where the method carries a name.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $capabilities  the client's capabilities; none by default
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public static function current(string $method, array $params = [], int|string $id = 1, array $capabilities = []): array
    {
        $params['_meta'] = [
            MetaKey::PROTOCOL_VERSION->value => ProtocolVersion::V2026_07_28->value,
            MetaKey::CLIENT_CAPABILITIES->value => (object) $capabilities,
        ];

        $headers = [
            RequestHeader::PROTOCOL_VERSION->value => ProtocolVersion::V2026_07_28->value,
            ...(new JsonRpcRequest($id, $method, $params))->mirroredHeaders(),
        ];

        return [self::legacy($method, $params, $id), $headers];
    }

    /**
     * A notification: no id.
     *
     * @return array<string, string>
     */
    public static function notification(string $method): array
    {
        return ['jsonrpc' => '2.0', 'method' => $method];
    }

    /**
     * A tools/call body in the legacy style.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public static function call(string $name, array $arguments = [], int|string $id = 1): array
    {
        return self::legacy('tools/call', ['name' => $name, 'arguments' => (object) $arguments], $id);
    }
}
