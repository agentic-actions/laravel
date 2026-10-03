<?php

namespace Tests\Fixtures\Streaming;

use AgenticActions\Attributes\UseToolset;
use AgenticActions\Attributes\WithPageContext;

/**
 * The chat agent with the page the person has open.
 */
#[UseToolset('stream')]
#[WithPageContext]
final class PageAgent extends StreamingAgent {}
