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
 * An External action named for the web only.
 */
#[Expose(web: true)]
final class PublishNote extends Action
{
    protected string $description = 'Publish one of the signed-in author\'s notes.';

    protected ?Effect $effect = Effect::External;

    /**
     * The note to publish.
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
     * Publish the author's note.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        return $context->actor(User::class)->posts()->whereKey($input->integer('note'))->update(['status' => 'published']);
    }
}
