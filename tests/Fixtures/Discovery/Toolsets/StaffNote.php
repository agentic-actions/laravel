<?php

namespace Tests\Fixtures\Discovery\Toolsets;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\User;

/**
 * A support action only staff may run: its input-free authorize() decides from the actor alone.
 */
#[Expose(agents: ['support'])]
final class StaffNote extends Action
{
    protected string $description = 'Leave an internal note for the support staff.';

    protected ?Effect $effect = Effect::Write;

    /**
     * The note.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'note' => $schema->string()->required(),
        ];
    }

    /**
     * Staff only.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor instanceof User && str_ends_with($context->actor->email, '@staff.test');
    }

    /**
     * Keep the note.
     */
    public function handle(ActionContext $context, ValidatedInput $input): string
    {
        return $input->string('note')->toString();
    }
}
