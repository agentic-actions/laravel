<?php

namespace Tests\Workbench;

use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepResponse;
use RuntimeException;
use Tests\Fixtures\Streaming\OneStepGateway;

/**
 * A provider whose connection drops after the tool-call step: the second step throws an exception that is not
 * laravel/ai's own stream error, so it leaves the stream the way a failed HTTP call to a provider does.
 */
final class CrashingGateway extends OneStepGateway
{
    /**
     * Step 1: every tool call; step 2: the exception.
     */
    protected function nextStep(TextProvider $provider, string $model, array $messages, ?array $schema): StepResponse
    {
        if ($this->steps > 0) {
            throw new RuntimeException('CANARY-EXCEPTION: the connection to the provider dropped.');
        }

        return parent::nextStep($provider, $model, $messages, $schema);
    }
}
