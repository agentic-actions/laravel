<?php

namespace Tests\Fixtures\Streaming;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use AgenticActions\Refusal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use RuntimeException;

/**
 * A Write that ends the way its input asks: done, denied by authorize(), refused, or crashed.
 */
#[Expose(agents: ['stream'])]
final class OutcomeNote extends Action
{
    protected string $description = 'Try a note that ends the way it is asked to.';

    protected ?Effect $effect = Effect::Write;

    /**
     * How the call ends: done, deny, refuse or crash.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'mode' => $schema->string()->required(),
        ];
    }

    /**
     * Denied when asked to be.
     */
    public function authorize(ActionContext $context, ValidatedInput $input): bool
    {
        return $input->string('mode')->toString() !== 'deny';
    }

    /**
     * Refuse or crash when asked to; otherwise do nothing.
     */
    public function handle(ActionContext $context, ValidatedInput $input): void
    {
        match ($input->string('mode')->toString()) {
            'refuse' => throw Refusal::make('CANARY-REFUSAL'),
            'crash' => throw new RuntimeException('CANARY-EXCEPTION'),
            default => null,
        };
    }
}
