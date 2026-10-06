<?php

namespace Tests\Fixtures\Authorize;

use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;

/**
 * TwoStepAuthorize's checks behind a nullable subclass of ValidatedInput, which the validated input never is: the call
 * after validation must fail rather than reach the record check with the parameter's default null.
 */
#[Expose]
final class TwoStepAuthorizeOnSubclass extends TwoStepReview
{
    /**
     * Before the input: the actor may review notes. After validation: the note is the actor's.
     */
    public function authorize(ActionContext $context, ?ReviewInput $input = null): bool
    {
        return $this->allows($context, $input);
    }
}
