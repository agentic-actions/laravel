<?php

namespace Tests\Fixtures\Approvals;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\Post;

/**
 * An External action offered to agents after a confirmation: it publishes one of the author's posts. Its card shows
 * the title; the body, too long for a card, is bound to the confirmation without being shown.
 */
#[Expose(agents: ['approvals'])]
final class ConfirmedPublish extends Action
{
    protected string $description = 'Publish one of the signed-in author\'s posts.';

    protected ?Effect $effect = Effect::External;

    protected bool $tenantScoped = false;

    /**
     * The post to publish.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post' => $schema->integer()->required(),
        ];
    }

    /**
     * Only the post's author.
     */
    public function authorize(ActionContext $context, ValidatedInput $input): bool
    {
        return $context->find(Post::class, $input->integer('post'))->user()->is($context->actor());
    }

    /**
     * The title the person reads.
     */
    public function approvalSummary(ActionContext $context, ValidatedInput $input): array
    {
        return ['Post' => $context->find(Post::class, $input->integer('post'))->title];
    }

    /**
     * The body the person confirms without reading it on the card.
     */
    public function approvalBinding(ActionContext $context, ValidatedInput $input): array
    {
        return ['body' => $context->find(Post::class, $input->integer('post'))->body];
    }

    /**
     * Publish the post.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        return $context->find(Post::class, $input->integer('post'))->update(['status' => 'published']);
    }
}
