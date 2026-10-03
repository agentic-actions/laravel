<?php

namespace Tests\Fixtures\Streaming;

use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\Citation;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;

/**
 * laravel/ai's fake gateway, reshaped the way a real provider answers: step 1 carries every configured tool call at
 * once (and $lead's words before them), step 2 the text, with its reasoning, citations and usage. Not final:
 * ErrorGateway ends in an error instead.
 */
class OneStepGateway extends FakeTextGateway
{
    /**
     * The steps answered so far.
     */
    protected int $steps = 0;

    /**
     * Create the gateway.
     *
     * @param  list<ToolCall>  $toolCalls
     * @param  list<Citation>  $citations
     */
    public function __construct(
        protected array $toolCalls = [],
        protected string $text = 'Done.',
        protected ?TextUsage $usage = null,
        protected string $reasoning = '',
        protected array $citations = [],
        protected string $lead = '',
    ) {
        parent::__construct([]);
    }

    /**
     * Answer the given agent's turns with this gateway, as laravel/ai's own fake() does with its stock gateway.
     *
     * @param  class-string  $agent
     */
    public function fake(string $agent): static
    {
        $gateway = $this;

        (function () use ($agent, $gateway): void {
            $this->fakeAgentGateways[$agent] = $gateway;
        })->call(app(AiManager::class));

        return $this;
    }

    /**
     * Step 1: every tool call; later steps: the text.
     */
    protected function nextStep(TextProvider $provider, string $model, array $messages, ?array $schema): StepResponse
    {
        $meta = new Meta($provider->name(), $model, collect($this->citations));

        if ($this->steps++ === 0 && $this->toolCalls !== []) {
            return new StepResponse($this->lead, $this->toolCalls, FinishReason::ToolCalls, new TextUsage, $meta);
        }

        return new StepResponse($this->text, [], FinishReason::Stop, $this->usage ?? new TextUsage, $meta, reasoning: $this->reasoning);
    }
}
