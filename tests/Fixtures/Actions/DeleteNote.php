<?php

namespace Tests\Fixtures\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\User;

/**
 * A Destructive action under a bare #[Expose]: agents skip it until a toolset is named, and MCP never serves it.
 */
#[Expose]
final class DeleteNote extends Action
{
    protected string $description = 'Delete one of the signed-in author\'s notes.';

    protected ?Effect $effect = Effect::Destructive;

    /**
     * The note to delete.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'note' => $schema->integer()->required(),
        ];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Delete the author's note.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        return $context->actor(User::class)->posts()->whereKey($input->integer('note'))->delete();
    }
}
