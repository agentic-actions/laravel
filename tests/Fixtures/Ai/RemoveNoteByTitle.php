<?php

namespace Tests\Fixtures\Ai;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use AgenticActions\Refusal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\User;

/**
 * An agent names a note by title; the canonical input is its id (Translate, step 4b).
 */
#[Expose(agents: ['support'])]
final class RemoveNoteByTitle extends Action
{
    protected string $description = 'Remove one of the signed-in author\'s notes by its title.';

    protected ?Effect $effect = Effect::Write;

    /**
     * The canonical input.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'note' => $schema->integer()->required(),
        ];
    }

    /**
     * What an agent is offered.
     */
    public function agentSchema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
        ];
    }

    /**
     * Find the author's note by title, or refuse with the titles the author has.
     */
    public function fromAgent(ValidatedInput $input, ActionContext $context): array
    {
        $notes = $context->actor(User::class)->posts()->orderBy('id')->get();
        $note = $notes->firstWhere('title', $input->string('title')->toString());

        if ($note === null) {
            throw Refusal::make('No note by that title.')->on('title')->listing($notes->pluck('title')->all());
        }

        return ['note' => $note->getKey()];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Remove the author's note.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        return $context->actor(User::class)->posts()->whereKey($input->integer('note'))->delete();
    }
}
