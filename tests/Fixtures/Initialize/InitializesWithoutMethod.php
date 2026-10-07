<?php

namespace Tests\Fixtures\Initialize;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;

/**
 * A Read that names a table in $initializes but declares no initialize(), so nothing ever adds the row.
 */
#[Expose]
final class InitializesWithoutMethod extends Action
{
    protected string $description = 'Show the signed-in author\'s share link.';

    protected ?Effect $effect = Effect::Read;

    /**
     * The table nothing adds rows to.
     *
     * @var list<string>
     */
    protected array $initializes = ['share_links'];

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Nothing.
     */
    public function handle(): null
    {
        return null;
    }
}
