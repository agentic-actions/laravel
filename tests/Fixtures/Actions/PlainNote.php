<?php

namespace Tests\Fixtures\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\User;

/**
 * Discovered, and exposed nowhere.
 */
final class PlainNote extends Action
{
    protected ?Effect $effect = Effect::Write;

    /**
     * The note's title.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
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
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        return $context->actor(User::class)->posts()->create(['title' => $input->string('title')->toString(), 'body' => 'plain', 'status' => 'draft']);
    }
}
