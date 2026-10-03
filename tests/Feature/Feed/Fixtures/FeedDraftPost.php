<?php

namespace Tests\Feature\Feed\Fixtures;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\User;

/**
 * An account-level Write touching posts: with no tenant, its touch lands in the actor's bucket.
 */
#[Expose]
final class FeedDraftPost extends Action
{
    protected string $description = 'Save a draft post for the signed-in author.';

    protected ?Effect $effect = Effect::Write;

    protected array $touches = ['posts'];

    protected bool $tenantScoped = false;

    /**
     * The draft's title.
     */
    public function schema(JsonSchema $schema): array
    {
        return ['title' => $schema->string()->required()];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Save the draft; the caller gets its id.
     */
    public function handle(ActionContext $context, ValidatedInput $input): int
    {
        return $context->actor(User::class)->posts()->create([
            'title' => $input->string('title')->toString(),
            'body' => 'draft',
            'status' => 'draft',
        ])->getKey();
    }
}
