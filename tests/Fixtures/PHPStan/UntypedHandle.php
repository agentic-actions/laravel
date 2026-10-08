<?php

namespace Tests\Fixtures\PHPStan;

use AgenticActions\Action;
use AgenticActions\ActionContext;

/**
 * A handle() with no declared return type, which PHPStan reads as mixed: tests/Unit/PHPStan/data/run.php.
 */
final class UntypedHandle extends Action
{
    public function handle(ActionContext $context)
    {
        return 1;
    }
}
