<?php

namespace Tests\Fixtures\Mcp\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\Post;

/**
 * A model speaks in headlines; the canonical input has a title and a body. It records the input handle() receives.
 */
#[Expose(mcp: true)]
final class McpTranslated extends Action
{
    /**
     * The validated canonical input of the last run.
     *
     * @var array<string, mixed>|null
     */
    public static ?array $received = null;

    protected string $description = 'Create a post from a headline.';

    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    /**
     * The canonical input.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(20)->required(),
            'body' => $schema->string()->required(),
        ];
    }

    /**
     * What a model is offered.
     */
    public function agentSchema(JsonSchema $schema): ?array
    {
        return [
            'headline' => $schema->string()->max(40)->required(),
        ];
    }

    /**
     * Turn the headline into a title and a body.
     */
    public function fromAgent(ValidatedInput $input, ActionContext $context): array
    {
        return ['title' => $input->string('headline')->toString(), 'body' => 'From a model.'];
    }

    /**
     * Any signed-in person.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Save the post.
     */
    public function handle(ActionContext $context, ValidatedInput $input): Post
    {
        self::$received = $input->all();

        return Post::query()->forceCreate([...$input->all(), 'status' => 'draft', 'user_id' => $context->actor()->getAuthIdentifier()]);
    }
}
