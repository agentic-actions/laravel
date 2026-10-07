<?php

namespace Tests\Fixtures\Approvals;

use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;

/**
 * A scripted model on the provider itself, so a paused turn resumes exactly as it does in production: the first step
 * makes the tool calls a test gives it (ids call_1, call_2, …), after $lead's words; every later step answers with
 * $text. It never reads the request's arguments back. Installed on the provider with install(), never through fake(),
 * so a confirmed call resumes on the path production runs.
 */
final class ScriptedGateway extends FakeTextGateway
{
    /**
     * The steps answered so far.
     */
    private int $steps = 0;

    /**
     * Everything the model was sent, one list of messages per step, so a test can read what reached the model.
     *
     * @var list<array<int, mixed>>
     */
    public array $sent = [];

    /**
     * Script the model.
     *
     * @param  list<array{0: string, 1?: array<string, mixed>, 2?: string}>  $calls  a tool name, its arguments and, for a provider that picks its own ids, the call's id, per call
     */
    public function __construct(
        private readonly array $calls = [],
        private readonly string $text = 'Done.',
        private readonly string $lead = '',
    ) {
        parent::__construct([]);
    }

    /**
     * Answer the turns of the default provider, or of the named one, with this gateway, from now on in this test.
     */
    public function install(?string $provider = null): self
    {
        Ai::textProvider($provider)->useTextGateway($this);

        return $this;
    }

    /**
     * How many steps the model has answered.
     */
    public function steps(): int
    {
        return $this->steps;
    }

    /**
     * The first step makes every scripted call; later steps answer with the text.
     */
    protected function nextStep(TextProvider $provider, string $model, array $messages, ?array $schema): StepResponse
    {
        $meta = new Meta($provider->name(), $model);
        $this->sent[] = $messages;

        if ($this->steps++ === 0 && $this->calls !== []) {
            $toolCalls = [];

            foreach ($this->calls as $index => $call) {
                $toolCalls[] = new ToolCall($call[2] ?? 'call_'.($index + 1), $call[0], $call[1] ?? []);
            }

            return new StepResponse($this->lead, $toolCalls, FinishReason::ToolCalls, new TextUsage, $meta);
        }

        return new StepResponse($this->text, [], FinishReason::Stop, new TextUsage, $meta);
    }
}
