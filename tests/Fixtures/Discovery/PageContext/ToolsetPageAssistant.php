<?php

namespace Tests\Fixtures\Discovery\PageContext;

use AgenticActions\Attributes\UseToolset;
use AgenticActions\Attributes\WithPageContext;

/**
 * An agent with a toolset and the page context.
 */
#[UseToolset('notes')]
#[WithPageContext]
final class ToolsetPageAssistant
{
    //
}
