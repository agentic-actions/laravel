<?php

namespace Tests\Fixtures\Elicitation;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Ask;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * AskingDraft's fields behind a bare #[Expose], so it is served over MCP with or without laravel/ai: an asking action
 * whose incomplete MCP call is refused naming the fields.
 */
#[Expose]
final class McpAskingDraft extends Action
{
    /**
     * The validated input the last handle() ran with.
     *
     * @var array<string, mixed>|null
     */
    public static ?array $handled = null;

    protected string $description = 'Draft a post for the signed-in author.';

    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    protected bool $askForMissing = true;

    /**
     * The post's fields.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->title('Title')->min(1)->max(120)->required(),
            'body' => $schema->string()->title('Body')->max(5000)->required(),
            'status' => $schema->string()->title('Status')->enum(['draft', 'published'])->required(),
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
     * The form's sentence and the title for review.
     */
    public function ask(Ask $ask, ActionContext $context): Ask
    {
        return $ask->message('A few details for your post.')->confirm('title');
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
