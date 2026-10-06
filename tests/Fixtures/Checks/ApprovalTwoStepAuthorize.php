<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\Post;

/**
 * A Destructive agent action with a summary, whose authorize() takes a ValidatedInput that may be null: it checks the
 * caller before any input is read and the post after validation, so the Summary row passes it.
 */
#[Expose(agents: ['approvals'])]
final class ApprovalTwoStepAuthorize extends Action
{
    protected string $description = 'Archive a post.';

    protected ?Effect $effect = Effect::Destructive;

    protected bool $tenantScoped = false;

    /**
     * The post to archive.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post' => $schema->integer()->required(),
        ];
    }

    /**
     * Before the input: a signed-in author. After validation: the post is theirs.
     */
    public function authorize(ActionContext $context, ?ValidatedInput $input = null): bool
    {
        if ($context->actor === null) {
            return false;
        }

        return $input === null || $context->find(Post::class, $input->integer('post'))->user()->is($context->actor());
    }

    /**
     * The post's title.
     */
    public function approvalSummary(ActionContext $context, ValidatedInput $input): array
    {
        return ['Post' => $context->find(Post::class, $input->integer('post'))->title];
    }

    /**
     * Never reached by these tests.
     */
    public function handle(ActionContext $context): mixed
    {
        return null;
    }
}
