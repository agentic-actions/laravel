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

/**
 * A Destructive agent action with input but no approvalSummary() or approvalReason(): its card holds the package's
 * sentence alone, and actions:check fails it.
 */
#[Expose(agents: ['approvals'])]
final class UnsummarizedDelete extends Action
{
    protected string $description = 'Delete one of the signed-in author\'s posts, without saying which.';

    protected ?Effect $effect = Effect::Destructive;

    protected bool $tenantScoped = false;

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
     * Only the post's author.
     */
    public function authorize(ActionContext $context, ValidatedInput $input): bool
    {
        return $context->find(Post::class, $input->integer('post'))->user()->is($context->actor());
    }

    /**
     * Delete the post.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        Trace::record('UnsummarizedDelete::handle');

        $context->find(Post::class, $input->integer('post'))->delete();

        return null;
    }
}
