<?php

namespace Tests\Fixtures\Approvals;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Tests\Fixtures\Actions\Trace;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/**
 * A Destructive agent action in the agent's own vocabulary: the model names a post by title, the canonical input is
 * its id, and the card is built from the translated input.
 */
#[Expose(agents: ['approvals'])]
final class DeleteByTitle extends Action
{
    protected string $description = 'Delete one of the signed-in author\'s posts by its title.';

    protected ?Effect $effect = Effect::Destructive;

    protected bool $tenantScoped = false;

    /**
     * The canonical input.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post' => $schema->integer()->required(),
        ];
    }

    /**
     * What an agent is offered.
     */
    public function agentSchema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
        ];
    }

    /**
     * The author's post with that title; 0 when there is none, which authorize() then refuses as not found.
     */
    public function fromAgent(ValidatedInput $input, ActionContext $context): array
    {
        $post = $context->actor(User::class)->posts()->where('title', $input->string('title')->toString())->first();

        return ['post' => $post?->getKey() ?? 0];
    }

    /**
     * Only the post's author.
     */
    public function authorize(ActionContext $context, ValidatedInput $input): bool
    {
        return $context->find(Post::class, $input->integer('post'))->user()->is($context->actor());
    }

    /**
     * The post's title.
     */
    public function approvalSummary(ActionContext $context, ValidatedInput $input): array
    {
        return ['Post' => $context->find(Post::class, $input->integer('post'))->title];
    }

    /**
     * Delete the post.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        Trace::record('DeleteByTitle::handle');

        $context->find(Post::class, $input->integer('post'))->delete();

        return null;
    }
}
