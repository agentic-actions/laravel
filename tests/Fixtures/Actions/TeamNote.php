<?php

namespace Tests\Fixtures\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/**
 * Tenant-scoped by default: tenancy, membership and token binding.
 */
#[Expose]
final class TeamNote extends Action
{
    protected string $description = 'Create a note in the current team.';

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
     * Save the note in the team.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        return $context->actor(User::class)->posts()->create([
            'title' => $input->string('title')->toString(),
            'body' => 'team',
            'status' => 'draft',
            'team_id' => $context->tenant(Team::class)->getKey(),
        ]);
    }
}
