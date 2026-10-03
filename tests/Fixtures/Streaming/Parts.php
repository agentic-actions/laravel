<?php

namespace Tests\Fixtures\Streaming;

use AgenticActions\Streaming\ActionsProtocol;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use UnexpectedValueException;

/**
 * Reads a streamed body the way a client does: one part per "data:" line, and the rows it leaves.
 */
final class Parts
{
    /**
     * The body's parts in order, "[DONE]" as the string itself.
     *
     * @return list<array<string, mixed>|'[DONE]'>
     */
    public static function of(string $body): array
    {
        $parts = [];

        foreach (explode("\n\n", trim($body)) as $frame) {
            if (! str_starts_with($frame, 'data: ')) {
                throw new UnexpectedValueException("Not a data line: [{$frame}]");
            }

            $data = substr($frame, 6);

            $parts[] = $data === '[DONE]' ? '[DONE]' : json_decode($data, true, flags: JSON_THROW_ON_ERROR);
        }

        return $parts;
    }

    /**
     * The whole body of a streamable response sent through the protocol.
     */
    public static function body(StreamableAgentResponse $response, ?ActionsProtocol $protocol = null): string
    {
        return TestResponse::fromBaseResponse($response->usingProtocol($protocol ?? new ActionsProtocol)->toResponse(request()))
            ->streamedContent();
    }

    /**
     * Each part's type, "[DONE]" as itself.
     *
     * @param  list<array<string, mixed>|'[DONE]'>  $parts
     * @return list<string>
     */
    public static function types(array $parts): array
    {
        return array_map(fn (array|string $part): string => is_string($part) ? $part : (string) $part['type'], $parts);
    }

    /**
     * The rows a client keeps: each data-action id once, in order of first appearance, with its last data.
     *
     * @param  list<array<string, mixed>|'[DONE]'>  $parts
     * @return list<array<string, mixed>>
     */
    public static function rows(array $parts): array
    {
        $rows = [];

        foreach ($parts as $part) {
            if (is_array($part) && $part['type'] === 'data-action') {
                $rows[$part['id']] = $part['data'];
            }
        }

        return array_values($rows);
    }
}
