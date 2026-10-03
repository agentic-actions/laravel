<?php

namespace Tests\Fixtures\Elicitation;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Ask;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\Post;

/**
 * A tenant-scoped Write that asks, behind a bare #[Expose], so an MCP client that shows forms is asked for what a call
 * left out. Its own rules() refuse a title another post already has; it sends its validation messages to a model, and
 * counts its runs.
 */
#[Expose]
final class McpAskingTeamPost extends Action
{
    /**
     * The validated input of each handle() run, in order.
     *
     * @var list<array<string, mixed>>
     */
    public static array $handled = [];

    protected string $description = 'Draft a post in the current team.';

    protected ?Effect $effect = Effect::Write;

    protected array $touches = ['posts'];

    protected bool $askForMissing = true;

    protected bool $validationMessagesToModel = true;

    /**
     * The post's fields.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->title('Title')->min(1)->max(120)->required(),
            'body' => $schema->string()->title('Body')->description('What the post says.')->max(5000)->required(),
            'status' => $schema->string()->title('Status')->enum(['draft', 'published'])->required(),
        ];
    }

    /**
     * A title no other post has.
     */
    public function rules(ActionContext $context): array
    {
        return ['title' => 'unique:posts,title'];
    }

    /**
     * Any signed-in member; membership already ran.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * The form's sentence, the title for review, the body as several lines and the statuses' titles.
     */
    public function ask(Ask $ask, ActionContext $context): Ask
    {
        return $ask
            ->message('A few details for your post.')
            ->confirm('title')
            ->textarea('body')
            ->choices('status', ['draft' => 'Draft', 'published' => 'Published']);
    }

    /**
     * Save the draft in the team.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        self::$handled[] = $input->all();

        Post::query()->forceCreate([
            ...$input->only(['title', 'body', 'status']),
            'team_id' => $context->tenant()->getKey(),
            'user_id' => $context->actor?->getAuthIdentifier(),
        ]);

        return null;
    }
}
