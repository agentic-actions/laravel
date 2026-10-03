<?php

namespace Tests\Fixtures\Streaming;

use AgenticActions\Attributes\UseToolset;

/**
 * The chat agent the stream tests run: the stream toolset, no page context.
 */
#[UseToolset('stream')]
final class StreamAgent extends StreamingAgent {}
