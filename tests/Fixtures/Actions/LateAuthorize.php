<?php

namespace Tests\Fixtures\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Illuminate\Validation\Rule;
use Workbench\App\Models\Post;

/**
 * An authorize() that takes the validated input: it checks the record after validation (step 7).
 */
#[Expose]
final class LateAuthorize extends Action
{
    protected string $description = 'Mark one of the signed-in author\'s notes as reviewed.';

    protected ?Effect $effect = Effect::Write;

    /**
     * The note to review.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->integer()->required(),
        ];
    }

    /**
     * The note must exist and not be archived. The rule is scoped, so it passes the id rows of actions:check, but not
     * to the actor: another author's note passes validation and meets authorize().
     */
    public function rules(ActionContext $context): array
    {
        Trace::record('rules');

        return [
            'post_id' => [Rule::exists('posts', 'id')->whereNot('status', 'archived')],
        ];
    }

    /**
     * Record the step.
     */
    public function prepareForValidation(array $input, ActionContext $context): array
    {
        Trace::record('prepareForValidation');

        return $input;
    }

    /**
     * The note belongs to the actor.
     */
    public function authorize(ActionContext $context, ValidatedInput $input): bool
    {
        Trace::record('authorize');

        return Post::query()
            ->whereKey($input->integer('post_id'))
            ->where('user_id', $context->actor?->getAuthIdentifier())
            ->exists();
    }

    /**
     * Mark the note.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        Trace::record('handle');

        return Post::query()->whereKey($input->integer('post_id'))->update(['status' => 'reviewed']);
    }
}
