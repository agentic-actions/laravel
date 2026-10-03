<?php

namespace Tests\Fixtures\Elicitation;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Ask;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\Team;

/**
 * A tenant's Write whose owner is picked from the tenant's members, opening on the actor, and whose posts are picked
 * from a numeric list.
 */
#[Expose(agents: ['asking'])]
final class AskingChoices extends Action
{
    /**
     * The validated input the last handle() ran with.
     *
     * @var array<string, mixed>|null
     */
    public static ?array $handled = null;

    protected string $description = 'Hand posts to a member of the team.';

    protected ?Effect $effect = Effect::Write;

    protected bool $askForMissing = true;

    /**
     * The owner and the posts.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'owner' => $schema->integer()->title('Owner')->required(),
            'ids' => $schema->array()->items($schema->integer())->title('Posts'),
        ];
    }

    /**
     * Any member.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * The tenant's members, the actor first chosen, and three posts.
     */
    public function ask(Ask $ask, ActionContext $context): Ask
    {
        /** @var array<int, string> $members */
        $members = $context->tenant(Team::class)->users()->orderBy('users.id')->pluck('name', 'users.id')->all();

        return $ask
            ->choices('owner', $members)
            ->default('owner', $context->actor()->getAuthIdentifier())
            ->choices('ids', [1 => 'One', 2 => 'Two', 3 => 'Three']);
    }

    /**
     * Record the run.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        self::$handled = $input->all();

        return null;
    }
}
