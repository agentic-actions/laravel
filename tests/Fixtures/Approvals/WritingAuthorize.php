<?php

namespace Tests\Fixtures\Approvals;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Tests\Fixtures\Actions\Trace;
use Workbench\App\Models\User;

/**
 * A Destructive agent action whose authorize() writes a row before it allows the call.
 */
#[Expose(agents: ['approvals'])]
final class WritingAuthorize extends Action
{
    protected string $description = 'Delete a post after logging the attempt.';

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
     * Write a row, then allow any signed-in author.
     */
    public function authorize(ActionContext $context, ValidatedInput $input): bool
    {
        $context->actor(User::class)->posts()->create(['title' => 'WRITTEN-BY-AUTHORIZE', 'body' => 'x', 'status' => 'draft']);

        return true;
    }

    /**
     * The post's id.
     */
    public function approvalSummary(ActionContext $context, ValidatedInput $input): array
    {
        return ['Post' => $input->integer('post')];
    }

    /**
     * Record the run.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        Trace::record('WritingAuthorize::handle');

        return null;
    }
}
