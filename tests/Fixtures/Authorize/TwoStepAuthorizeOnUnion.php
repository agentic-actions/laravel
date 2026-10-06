<?php

namespace Tests\Fixtures\Authorize;

use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use Illuminate\Support\ValidatedInput;

/**
 * TwoStepAuthorize's checks behind a union that holds ValidatedInput and null: the input must still reach the second
 * call.
 */
#[Expose]
final class TwoStepAuthorizeOnUnion extends TwoStepReview
{
    /**
     * Before the input: the actor may review notes. After validation: the note is the actor's.
     *
     * @param  ValidatedInput|array<string, mixed>|null  $input
     */
    public function authorize(ActionContext $context, ValidatedInput|array|null $input = null): bool
    {
        return $this->allows($context, is_array($input) ? new ValidatedInput($input) : $input);
    }
}
