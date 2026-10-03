<?php

namespace App\Ai;

use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;

/**
 * laravel/ai's fake gateway, answering the way a provider does: step 1 calls both slow tools at once, step 2 replies.
 * Everything after nextStep() is laravel/ai's own code.
 */
final class OneStepTwoToolsGateway extends FakeTextGateway
{
    /**
     * The steps answered so far.
     */
    private int $steps = 0;

    /**
     * Create the gateway for one probe run.
     */
    public function __construct(private readonly string $run)
    {
        parent::__construct([]);
    }

    /**
     * Answer ProbeAssistant's turns with this gateway, as laravel/ai's own fake() does with its stock gateway. The
     * manager's fake-gateway map is protected, so a closure bound to the manager writes it.
     */
    public function fake(): void
    {
        $gateway = $this;

        (function () use ($gateway): void {
            $this->fakeAgentGateways[ProbeAssistant::class] = $gateway;
        })->call(app(AiManager::class));
    }

    /**
     * Step 1: both tool calls; later steps: the reply, which names the run.
     */
    protected function nextStep(TextProvider $provider, string $model, array $messages, ?array $schema): StepResponse
    {
        $meta = new Meta($provider->name(), $model);

        if ($this->steps++ === 0) {
            return new StepResponse('', [
                new ToolCall('call_1', 'slow-note', ['run' => $this->run]),
                new ToolCall('call_2', 'SlowLookup', ['run' => $this->run]),
            ], FinishReason::ToolCalls, new TextUsage, $meta);
        }

        return new StepResponse("Saved the note and looked it up (run {$this->run}).", [], FinishReason::Stop, new TextUsage, $meta);
    }
}
