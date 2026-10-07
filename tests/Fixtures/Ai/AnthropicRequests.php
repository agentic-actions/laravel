<?php

namespace Tests\Fixtures\Ai;

use Illuminate\Support\Facades\Http;

/**
 * Answers laravel/ai's Anthropic gateway with a short text reply over Laravel's HTTP fake, and reads back the tools its
 * first request sent, as Anthropic would receive them.
 */
final class AnthropicRequests
{
    /**
     * Answer every Anthropic request in this test with the same reply.
     */
    public static function fake(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-test',
            'content' => [['type' => 'text', 'text' => 'Done.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);
    }

    /**
     * The tools of the first request, in order, each as its name, whether it is deferred, and whether it carries the
     * cache mark.
     *
     * @return list<array{0: string, 1: bool, 2: bool}>
     */
    public static function tools(): array
    {
        return array_map(
            fn (array $tool): array => [$tool['name'], $tool['defer_loading'] ?? false, isset($tool['cache_control'])],
            Http::recorded()[0][0]->data()['tools'] ?? [],
        );
    }
}
