<?php

namespace Tests\Fixtures\Approvals;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Tests\Fixtures\Actions\Trace;

/**
 * An External action offered to agents after a confirmation: its card shows the recipient only, never the note, and
 * reads the package's sentence for the effect.
 */
#[Expose(agents: ['approvals'])]
final class ConfirmedSend extends Action
{
    protected string $description = 'Send a note to someone outside the app.';

    protected ?Effect $effect = Effect::External;

    protected bool $tenantScoped = false;

    /**
     * The recipient and the note.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'to' => $schema->string()->max(500)->required(),
            'note' => $schema->string()->max(500),
        ];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context, ValidatedInput $input): bool
    {
        return $context->actor !== null;
    }

    /**
     * The recipient only.
     */
    public function approvalSummary(ActionContext $context, ValidatedInput $input): array
    {
        return ['To' => $input->string('to')->toString()];
    }

    /**
     * Record the send; nothing leaves the test.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        Trace::record('ConfirmedSend::handle');

        return null;
    }
}
