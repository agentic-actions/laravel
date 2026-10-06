<?php

namespace Tests\Fixtures\Context;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use RuntimeException;

/**
 * Remembers ActionContext::current() while it runs, as a model event or a service it calls would read it; with
 * nested, runs ForgetContext inside itself, and remembers the context again after it.
 */
#[Expose]
final class RememberContext extends Action
{
    /**
     * What ActionContext::current() read: before a nested run, inside it, and after it.
     *
     * @var list<ActionContext|null>
     */
    public static array $seen = [];

    protected string $description = 'Remember the context it runs in.';

    protected ?Effect $effect = Effect::Read;

    /**
     * Whether to run another action inside, or throw.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'nested' => $schema->boolean(),
            'throws' => $schema->boolean(),
        ];
    }

    /**
     * Anyone.
     */
    public function authorize(ActionContext $context): bool
    {
        return true;
    }

    /**
     * Read the current context, around a nested run when asked.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        self::$seen[] = ActionContext::current();

        if ($input->boolean('nested')) {
            ForgetContext::run([], ActionContext::system());
            self::$seen[] = ActionContext::current();
        }

        if ($input->boolean('throws')) {
            throw new RuntimeException('Broken.');
        }

        return null;
    }
}
