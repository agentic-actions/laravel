<?php

namespace Tests\Fixtures\Elicitation;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * An agentSchema() whose new title agents must give while the canonical input leaves it optional: requiredForAgents()
 * names an agent key, in a toolset of its own.
 */
#[Expose(agents: ['required'])]
final class RequiredAgentVocabulary extends Action
{
    /**
     * The validated input the last handle() ran with.
     *
     * @var array<string, mixed>|null
     */
    public static ?array $handled = null;

    protected string $description = 'Retitle a post, named by its current title.';

    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    /**
     * The canonical input: the post's id, and a title it may leave out.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post' => $schema->integer()->required(),
            'title' => $schema->string()->nullable(),
        ];
    }

    /**
     * What agents are offered: the current title, and a new one.
     */
    public function agentSchema(JsonSchema $schema): ?array
    {
        return [
            'current_title' => $schema->string()->required(),
            'title' => $schema->string()->nullable(),
        ];
    }

    /**
     * A model's call gives the new title.
     */
    public function requiredForAgents(): array
    {
        return ['title'];
    }

    /**
     * Every current title names post 1 here.
     */
    public function fromAgent(ValidatedInput $input, ActionContext $context): array
    {
        return ['post' => 1, 'title' => $input->input('title')];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Record the run.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        self::$handled = $input->all();

        return null;
    }
}
