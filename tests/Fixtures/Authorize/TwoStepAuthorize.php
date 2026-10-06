<?php

namespace Tests\Fixtures\Authorize;

use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use Illuminate\Support\ValidatedInput;

/**
 * An authorize() whose ValidatedInput may be null: it runs before any input is read (who may review notes at all,
 * also when the tool is listed), then again after validation with the input (this note is the actor's).
 */
#[Expose]
final class TwoStepAuthorize extends TwoStepReview
{
    /**
     * Before the input: the actor may review notes. After validation: the note is the actor's.
     */
    public function authorize(ActionContext $context, ?ValidatedInput $input = null): bool
    {
        return $this->allows($context, $input);
    }
}
