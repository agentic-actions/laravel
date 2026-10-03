<?php

namespace Tests\Fixtures\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/**
 * The happy path on every surface. Not final: ChildNote extends it.
 */
#[Expose]
class CreateNote extends Action
{
    protected string $description = 'Create a note for the signed-in author.';

    protected ?Effect $effect = Effect::Write;

    /**
     * The note's fields.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(20)->required(),
            'body' => $schema->string()->required(),
            'excerpt' => $schema->string()->nullable(),
        ];
    }

    /**
     * What the caller gets back.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'title' => $schema->string()->required(),
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
     * Save the note.
     */
    public function handle(ActionContext $context, ValidatedInput $input): Post
    {
        return $context->actor(User::class)->posts()->create([...$input->all(), 'status' => 'draft']);
    }
}
