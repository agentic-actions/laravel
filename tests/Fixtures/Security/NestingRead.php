<?php

namespace Tests\Fixtures\Security;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;

/**
 * A Read with no #[Expose] that runs Inside's code in the hook Inside::$hook names.
 */
final class NestingRead extends Action
{
    protected ?Effect $effect = Effect::Read;

    protected bool $tenantScoped = false;

    /**
     * Any signed-in person.
     */
    public function authorize(ActionContext $context): bool
    {
        $this->inside('authorize', $context);

        return $context->actor !== null;
    }

    /**
     * The input as it came.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function prepareForValidation(array $input, ActionContext $context): array
    {
        $this->inside('prepareForValidation', $context);

        return $input;
    }

    /**
     * Nothing to read.
     */
    public function handle(ActionContext $context): string
    {
        $this->inside('handle', $context);

        return 'read';
    }

    /**
     * The package's own sentence.
     */
    public function modelReply(mixed $result, ActionContext $context): ?string
    {
        $this->inside('modelReply', $context);

        return null;
    }

    /**
     * Run Inside's code when this is its hook.
     */
    private function inside(string $hook, ActionContext $context): void
    {
        if (Inside::$hook === $hook) {
            Inside::call($context);
        }
    }
}
