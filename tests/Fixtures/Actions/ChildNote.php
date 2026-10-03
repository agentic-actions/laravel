<?php

namespace Tests\Fixtures\Actions;

/**
 * Extends an exposed action without repeating #[Expose]: attributes are not inherited.
 */
final class ChildNote extends CreateNote
{
    //
}
