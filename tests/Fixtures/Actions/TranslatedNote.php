<?php

namespace Tests\Fixtures\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use AgenticActions\Refusal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/**
 * An agent speaks in headlines; the canonical input has a title and a body (Translate, step 4b). A bare #[Expose]
 * skips the web quietly, because the class overrides agentSchema().
 */
#[Expose]
final class TranslatedNote extends Action
{
    protected string $description = 'Create a note from a headline.';

    protected ?Effect $effect = Effect::Write;

    /**
     * The canonical input.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(20)->required(),
            'body' => $schema->string()->required(),
        ];
    }

    /**
     * What an agent is offered.
     */
    public function agentSchema(JsonSchema $schema): ?array
    {
        return [
            'headline' => $schema->string()->max(40)->required(),
        ];
    }

    /**
     * Turn the headline into a title and a body.
     */
    public function fromAgent(ValidatedInput $input, ActionContext $context): array
    {
        $headline = $input->string('headline')->toString();

        return match ($headline) {
            'refuse' => throw Refusal::make('No headline like that.')->on('headline')->listing(['First', 'Second'])->details(['hidden' => 'x']),
            'missing' => throw (new ModelNotFoundException)->setModel(Post::class, [17]),
            'too long for a title' => ['title' => str_repeat('x', 30), 'body' => 'From an agent.'],
            default => ['title' => $headline, 'body' => 'From an agent.'],
        };
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
