<?php

namespace Tests\Fixtures\Streaming;

use AgenticActions\Contracts\DescribesActivity;
use AgenticActions\Streaming\Activity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\Actions\Trace;

/**
 * A hand-written tool with a row, whose report the test chooses, and which records whether the abort is ignored.
 */
final class TracedTool implements DescribesActivity, Tool
{
    /**
     * What handle() reports: ok, refused, crashed, twice (refused, then ok) or nothing.
     */
    public static string $mode = 'ok';

    /**
     * Fixed labels.
     */
    public function activityLabel(bool $finished): ?string
    {
        return $finished ? 'Traced' : 'Tracing…';
    }

    /**
     * What the tool does.
     */
    public function description(): string
    {
        return 'Trace the call.';
    }

    /**
     * No input.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * Record ignore_user_abort(), then report as the mode says.
     */
    public function handle(Request $request): string
    {
        Trace::record('TracedTool ignore_user_abort='.ignore_user_abort());

        if (self::$mode === 'ok') {
            Activity::record($request, true, ['notes']);
        } elseif (self::$mode === 'refused') {
            Activity::record($request, false);
        } elseif (self::$mode === 'crashed') {
            Activity::record($request, false, crashed: true);
        } elseif (self::$mode === 'twice') {
            Activity::record($request, false);
            Activity::record($request, true, ['notes']);
        }

        return 'Traced.';
    }
}
