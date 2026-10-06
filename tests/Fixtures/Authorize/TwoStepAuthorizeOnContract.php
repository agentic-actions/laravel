<?php

namespace Tests\Fixtures\Authorize;

use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use Illuminate\Contracts\Support\ValidatedData;
use Illuminate\Support\ValidatedInput;

/**
 * TwoStepAuthorize's checks behind the ValidatedData contract that ValidatedInput implements, nullable: the input must
 * still reach the second call, or a record check written as "null means the first call" would let every call through.
 */
#[Expose]
final class TwoStepAuthorizeOnContract extends TwoStepReview
{
    /**
     * Before the input: the actor may review notes. After validation: the note is the actor's.
     */
    public function authorize(ActionContext $context, ?ValidatedData $input = null): bool
    {
        return $this->allows($context, $input === null ? null : new ValidatedInput($input->toArray()));
    }
}
