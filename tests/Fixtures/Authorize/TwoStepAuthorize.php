<?php

namespace Tests\Fixtures\Authorize;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Tests\Fixtures\Actions\Trace;
use Workbench\App\Models\Post;

/**
 * An authorize() whose ValidatedInput may be null: it runs before any input is read (who may review notes at all,
 * also when the tool is listed), then again after validation with the input (this note is the actor's).
 */
#[Expose]
final class TwoStepAuthorize extends Action
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
     * Record the step.
     */
    public function rules(ActionContext $context): array
    {
        Trace::record('rules');

        return [];
    }

    /**
     * Before the input: the actor may review notes. After validation: the note is the actor's.
     */
    public function authorize(ActionContext $context, ?ValidatedInput $input = null): bool
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

    /**
     * Mark the note.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        Trace::record('handle');

        return Post::query()->whereKey($input->integer('post_id'))->update(['status' => 'reviewed']);
    }
}
