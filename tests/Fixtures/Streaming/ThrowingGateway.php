<?php

namespace Tests\Fixtures\Streaming;

use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepResponse;
use RuntimeException;

/**
 * OneStepGateway whose step after the tool calls throws, so an exception leaves the stream after a row has started.
 */
final class ThrowingGateway extends OneStepGateway
{
    /**
     * Step 1: every tool call; step 2: an exception.
     */
    protected function nextStep(TextProvider $provider, string $model, array $messages, ?array $schema): StepResponse
    {
        if ($this->steps > 0) {
            throw new RuntimeException('CANARY-EXCEPTION');
        }

        return parent::nextStep($provider, $model, $messages, $schema);
    }
}
