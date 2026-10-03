<?php

namespace Tests\Fixtures\Approvals;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Support\ValidatedInput;

/**
 * A Destructive agent action with no input whose summary has one row more than a card shows: it never gets a card.
 */
#[Expose(agents: ['approvals'])]
final class NineRows extends Action
{
    protected string $description = 'Clear nine things at once.';

    protected ?Effect $effect = Effect::Destructive;

    protected bool $tenantScoped = false;

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Nine rows.
     */
    public function approvalSummary(ActionContext $context, ValidatedInput $input): array
    {
        $rows = [];

        foreach (range(1, 9) as $row) {
            $rows["Row {$row}"] = $row;
        }

        return $rows;
    }

    /**
     * Nothing to do.
     */
    public function handle(ActionContext $context): mixed
    {
        return null;
    }
}
