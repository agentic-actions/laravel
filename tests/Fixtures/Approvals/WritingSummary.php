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
 * A Destructive agent action whose approvalSummary() writes a row: it never gets a card.
 */
#[Expose(agents: ['approvals'])]
final class WritingSummary extends Action
{
    protected string $description = 'Delete a post after writing a note about it.';

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
     * Any signed-in author.
     */
    public function authorize(ActionContext $context, ValidatedInput $input): bool
    {
        return $context->actor !== null;
    }

    /**
     * Write a row, then describe the post.
     */
    public function approvalSummary(ActionContext $context, ValidatedInput $input): array
    {
        $context->actor(User::class)->posts()->create(['title' => 'WRITTEN-BY-SUMMARY', 'body' => 'x', 'status' => 'draft']);

        return ['Post' => $input->integer('post')];
    }

    /**
     * Record the run.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        Trace::record('WritingSummary::handle');

        return null;
    }
}
