<?php

namespace Tests\Fixtures\OAuth\Queued;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ValidatedInput;
use Tests\Fixtures\OAuth\Actions\CreateTeamPost;

/**
 * A tenant-scoped Write that queues CreateTeamPost in its own tenant, and in the tenant a test names, with the grants
 * its caller had.
 */
#[Expose]
final class QueueTeamPost extends Action
{
    /**
     * Another tenant to queue the same post in, or null.
     */
    public static ?Model $alsoIn = null;

    protected string $description = 'Queue a draft post in the current team.';

    protected ?Effect $effect = Effect::Write;

    /**
     * The post's title.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(40)->required(),
        ];
    }

    /**
     * Any signed-in person; membership already ran.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Queue the post.
     */
    public function handle(ActionContext $context, ValidatedInput $input): string
    {
        CreateTeamPost::dispatch(['title' => $input->string('title')->value()], $context);

        if (self::$alsoIn !== null) {
            CreateTeamPost::dispatch(['title' => $input->string('title')->value().' elsewhere'], ActionContext::mcp($context->actor(), self::$alsoIn));
        }

        return 'Queued.';
    }
}
