<?php

namespace Tests\Fixtures\Streaming;

use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;

/**
 * OneStepGateway spread over several steps, the way a model that keeps calling tools after reading their results
 * answers: each step makes its own tool calls, and the step after the last answers with the text.
 */
final class ManyStepsGateway extends OneStepGateway
{
    /**
     * Create the gateway.
     *
     * @param  list<list<ToolCall>>  $calls  each step's tool calls, in order
     */
    public function __construct(private readonly array $calls, string $text = 'Done.')
    {
        parent::__construct([], $text);
    }

    /**
     * Each step: its tool calls; the step after the last: the text.
     */
    protected function nextStep(TextProvider $provider, string $model, array $messages, ?array $schema): StepResponse
    {
        $meta = new Meta($provider->name(), $model);
        $calls = $this->calls[$this->steps++] ?? [];

        return $calls === []
            ? new StepResponse($this->text, [], FinishReason::Stop, new TextUsage, $meta)
            : new StepResponse('', $calls, FinishReason::ToolCalls, new TextUsage, $meta);
    }
}
