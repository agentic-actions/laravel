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
 * A Destructive agent action with a summary, whose authorize() runs before any input is read: its card could show a
 * post the person may not read, so the Summary row fails it.
 */
#[Expose(agents: ['approvals'])]
final class ApprovalLooseAuthorize extends Action
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
     * Any signed-in author, whatever the post.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
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
