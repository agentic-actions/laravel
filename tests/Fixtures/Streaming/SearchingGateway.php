<?php

namespace Tests\Fixtures\Streaming;

use Generator;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;

/**
 * OneStepGateway behind a provider that searches tools itself: before step 1's tool calls it streams the search it
 * ran, as a provider streams its own tools, with the query "CANARY-SEARCH" and the tool it found.
 */
final class SearchingGateway extends OneStepGateway
{
    /**
     * The search, on step 1 only, then the step.
     */
    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        if ($this->steps === 0) {
            $search = ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'tool_search_tool_regex', 'input' => ['query' => 'CANARY-SEARCH']];
            $found = ['type' => 'tool_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => ['type' => 'tool_search_tool_search_result', 'tool_references' => [['type' => 'tool_reference', 'tool_name' => 'CANARY-SEARCH-RESULT']]]];

            yield (new ProviderToolEvent('event_1', 'srvtoolu_1', 'server_tool_use', $search, 'started', time(), $provider->name()))->withInvocationId($invocationId);
            yield (new ProviderToolEvent('event_2', 'srvtoolu_1', 'server_tool_use', $search, 'completed', time(), $provider->name()))->withInvocationId($invocationId);
            yield (new ProviderToolEvent('event_3', 'srvtoolu_1', 'tool_search_tool_result', $found, 'result_received', time(), $provider->name()))->withInvocationId($invocationId);
        }

        return yield from parent::generateStreamStep($invocationId, $provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout, $stepContext);
    }
}
