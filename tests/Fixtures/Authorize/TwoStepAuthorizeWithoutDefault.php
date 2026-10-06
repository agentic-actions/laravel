<?php

namespace Tests\Fixtures\Authorize;

use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use Illuminate\Support\ValidatedInput;

/**
 * TwoStepAuthorize's checks behind a ValidatedInput that may be null and has no default: before the input, the package
 * itself must pass the null.
 */
#[Expose]
final class TwoStepAuthorizeWithoutDefault extends TwoStepReview
{
    /**
     * Before the input: the actor may review notes. After validation: the note is the actor's.
     */
    public function authorize(ActionContext $context, ?ValidatedInput $input): bool
    {
        return $this->allows($context, $input);
    }
}
