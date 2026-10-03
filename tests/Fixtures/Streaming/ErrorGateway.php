<?php

namespace Tests\Fixtures\Streaming;

use Generator;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextStart;

use function Laravel\Ai\ulid;

/**
 * A provider that reports an error inside the stream, after the configured tool-call step if any: with no step
 * response (laravel/ai then throws), or followed by one (the stream then ends without throwing). With $started
 * false, the error is the step's first event.
 */
final class ErrorGateway extends OneStepGateway
{
    /**
     * Create the gateway.
     *
     * @param  list<ToolCall>  $toolCalls
     */
    public function __construct(array $toolCalls = [], private readonly bool $respond = false, private readonly bool $started = true)
    {
        parent::__construct($toolCalls);
    }

    /**
     * The tool-call step as the fake streams it, then a step that ends in the provider's error.
     *
     * @return Generator<int, mixed, mixed, StepResponse|null>
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
        if ($this->steps === 0 && $this->toolCalls !== []) {
            return yield from parent::generateStreamStep($invocationId, $provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout, $stepContext);
        }

        $this->steps++;

        if ($this->started) {
            $messageId = ulid();

            yield (new StreamStart(ulid(), $provider->name(), $model, time()))->withInvocationId($invocationId);
            yield (new TextStart(ulid(), $messageId, time()))->withInvocationId($invocationId);
            yield (new TextDelta(ulid(), $messageId, 'Partly', time()))->withInvocationId($invocationId);
        }

        yield (new Error(ulid(), 'server_error', 'CANARY-PROVIDER', false, time()))->withInvocationId($invocationId);

        return $this->respond
            ? new StepResponse('Partly', [], FinishReason::Stop, new TextUsage, new Meta($provider->name(), $model))
            : null;
    }
}
