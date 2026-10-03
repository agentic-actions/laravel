<?php

namespace Tests\Feature\TypeScript\Fixtures;

use Tests\Fixtures\Actions\CreateNote;

/**
 * Named "eval" by default, a name a module's strict mode refuses as an export.
 */
final class EvalAction extends CreateNote
{
    //
}
