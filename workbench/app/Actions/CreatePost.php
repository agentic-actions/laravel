<?php

namespace Workbench\App\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use AgenticActions\Refusal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

#[Expose]
final class CreatePost extends Action
{
    protected string $description = 'Create a draft blog post. Publishing is a separate action.';

    protected ?Effect $effect = Effect::Write;

    protected array $touches = ['posts'];

    /**
     * Only matters once config('agentic-actions.tenant.model') is set: a draft belongs to its author, not a team.
     */
    protected bool $tenantScoped = false;

    /**
     * The post's fields.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(120)->required(),
            'body' => $schema->string()->required(),
            'excerpt' => $schema->string()->max(200)->nullable()->description('One line for listings. Left empty when omitted.'),
        ];
    }

    /**
     * What the caller gets back.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'title' => $schema->string()->required(),
        ];
    }

    /**
     * Any signed-in author may draft a post.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor instanceof User;
    }

    /**
     * The copilot row: the package's "Saving…" while it runs, then the action's own words.
     */
    public function activityLabel(ActionContext $context, bool $finished): ?string
    {
        return $finished ? __('Draft saved') : null;
    }

    /**
     * Save the draft.
     */
    public function handle(ActionContext $context, ValidatedInput $input): Post
    {
        $author = $context->actor(User::class);

        if ($author->posts()->where('title', $input->string('title')->toString())->exists()) {
            throw Refusal::make(__('You already have a post with that title.'))->on('title');
        }

        return $author->posts()->create([...$input->all(), 'status' => 'draft']);
    }
}
