<?php

namespace Tests\Fixtures\Queue;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\Post;

/**
 * A Write with no #[Expose], queued through Action::dispatch(): it records its run and saves a post for its actor,
 * in its tenant when it has one.
 */
final class QueuedWrite extends Action
{
    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    /**
     * A title, and a slot the caller's edge may fix.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'slot' => $schema->string()->nullable(),
        ];
    }

    /**
     * Any signed-in person, or the app's own work.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null || $context->isSystem();
    }

    /**
     * Record the run, and save a post when there is an actor.
     */
    public function handle(ActionContext $context, ValidatedInput $input): string
    {
        Recorder::record($context, $input->all());

        if ($context->actor !== null) {
            (new Post)->forceFill([
                'user_id' => $context->actor->getAuthIdentifier(),
                'team_id' => $context->tenant?->getKey(),
                'title' => $input->string('title')->toString(),
                'body' => 'queued',
                'status' => 'draft',
            ])->save();
        }

        return $input->string('title')->toString();
    }
}
