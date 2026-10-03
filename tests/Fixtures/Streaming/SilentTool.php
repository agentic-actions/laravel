<?php

namespace Tests\Fixtures\Streaming;

use AgenticActions\Streaming\Activity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\Actions\Trace;

/**
 * A hand-written tool with no DescribesActivity: it shows no row, even when it reports.
 */
final class SilentTool implements Tool
{
    /**
     * What the tool does.
     */
    public function description(): string
    {
        return 'Do something quietly.';
    }

    /**
     * No input.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * Record the call and report success, which no row receives.
     */
    public function handle(Request $request): string
    {
        Trace::record('SilentTool');

        Activity::record($request, true, ['notes']);

        return 'Quiet.';
    }
}
