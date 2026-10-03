<?php

namespace Workbench\App\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/**
 * Destructive: served on the web under teams/{team}, and offered to TeamAssistant, which runs it only after the member
 * confirms the card built by approvalSummary(). MCP never serves it. It declares $askForMissing, and still never asks:
 * a model's call missing the post is refused.
 */
#[Expose(web: true, agents: ['team'])]
final class DeletePost extends Action
{
    protected string $description = 'Delete one of your posts in the current team.';

    protected ?Effect $effect = Effect::Destructive;

    protected bool $askForMissing = true;

    protected array $touches = ['posts'];

    /**
     * The post to delete.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post' => $schema->integer()->required(),
        ];
    }

    /**
     * The post must be the actor's own, in this team. A post of another team reads as not found.
     */
    public function authorize(ActionContext $context, ValidatedInput $input): bool
    {
        return $context->find(Post::class, $input->integer('post'))->user()->is($context->actor(User::class));
    }

    /**
     * The card's sentence.
     */
    public function approvalReason(ActionContext $context): string
    {
        return __('Delete this post? This cannot be undone.');
    }

    /**
     * The post's title and status, from the record authorize() allowed.
     */
    public function approvalSummary(ActionContext $context, ValidatedInput $input): array
    {
        $post = $context->find(Post::class, $input->integer('post'));

        return [__('Post') => $post->title, __('Status') => $post->status];
    }

    /**
     * Delete it.
     */
    public function handle(ActionContext $context, ValidatedInput $input): void
    {
        $context->find(Post::class, $input->integer('post'))->delete();
    }
}
