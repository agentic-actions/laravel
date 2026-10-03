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
 * External: served on the web under teams/{team}, and offered to TeamAssistant, which runs it only after the member
 * confirms the card built by approvalSummary(). MCP never serves it.
 */
#[Expose(web: true, agents: ['team'])]
final class PublishPost extends Action
{
    protected string $description = 'Publish one of your drafts in the current team.';

    protected ?Effect $effect = Effect::External;

    protected array $touches = ['posts'];

    /**
     * The draft to publish.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post' => $schema->integer()->required(),
        ];
    }

    /**
     * What the caller gets back.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'status' => $schema->string()->required(),
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
        return __('Publish this post? Everyone will be able to read it.');
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
     * Publish it.
     */
    public function handle(ActionContext $context, ValidatedInput $input): Post
    {
        $post = $context->find(Post::class, $input->integer('post'));

        $post->update(['status' => 'published']);

        return $post;
    }
}
