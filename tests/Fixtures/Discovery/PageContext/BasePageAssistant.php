<?php

namespace Tests\Fixtures\Discovery\PageContext;

use AgenticActions\Attributes\WithPageContext;

/**
 * An abstract agent with the page context, which the scanner never records.
 */
#[WithPageContext]
abstract class BasePageAssistant
{
    //
}
