<?php

namespace Tests\Fixtures\Streaming;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Tests\Fixtures\Actions\Trace;

/**
 * A Write that records when it starts and ends, so a test can place each row against its own call.
 */
#[Expose(agents: ['stream'])]
final class SlowNote extends Action
{
    protected string $description = 'Save a note slowly.';

    protected ?Effect $effect = Effect::Write;

    /**
     * Which call this is.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'call' => $schema->string()->required(),
        ];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Record the start, wait a moment, record the end.
     */
    public function handle(ActionContext $context, ValidatedInput $input): void
    {
        Trace::record('start '.$input->string('call'));

        usleep(20_000);

        Trace::record('end '.$input->string('call'));
    }
}
