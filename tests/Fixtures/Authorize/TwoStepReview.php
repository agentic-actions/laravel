<?php

namespace Tests\Fixtures\Authorize;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Tests\Fixtures\Actions\Trace;
use Workbench\App\Models\Post;

/**
 * Marks one of the actor's notes as reviewed, behind the two checks of an authorize() whose ValidatedInput may be null:
 * before any input is read, who may review notes at all (also when the tool is listed); after validation, whether this
 * note is the actor's. Each subclass declares authorize() in one form of that parameter and hands it to allows().
 */
abstract class TwoStepReview extends Action
{
    /**
     * Whether the actor may review notes at all, as a role check would say.
     */
    public static bool $mayReview = true;

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
     * Record the step: it reads the input.
     */
    public function prepareForValidation(array $input, ActionContext $context): array
    {
        Trace::record('prepareForValidation');

        return $input;
    }

    /**
     * Record the step.
     */
    public function rules(ActionContext $context): array
    {
        Trace::record('rules');

        return [];
    }

    /**
     * Mark the note.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        Trace::record('handle');

        return Post::query()->whereKey($input->integer('post_id'))->update(['status' => 'reviewed']);
    }

    /**
     * Before the input: the actor may review notes. After validation: the note is the actor's.
     */
    protected function allows(ActionContext $context, ?ValidatedInput $input): bool
    {
        Trace::record($input === null ? 'authorize:before' : 'authorize:after');

        if (! self::$mayReview || $context->actor === null) {
            return false;
        }

        return $input === null || Post::query()
            ->whereKey($input->integer('post_id'))
            ->where('user_id', $context->actor->getAuthIdentifier())
            ->exists();
    }
}
