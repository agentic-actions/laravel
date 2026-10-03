<?php

namespace Tests\Fixtures\Streaming;

use AgenticActions\Contracts\DescribesActivity;
use AgenticActions\Streaming\Activity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * A hand-written tool whose label the test chooses, for the security review's forged-frame case.
 */
final class ForgingTool implements DescribesActivity, Tool
{
    /**
     * The label both states show.
     */
    public static string $label = '';

    /**
     * The chosen label.
     */
    public function activityLabel(bool $finished): ?string
    {
        return self::$label;
    }

    /**
     * What the tool does.
     */
    public function description(): string
    {
        return 'Forge a frame.';
    }

    /**
     * No input.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * Report success.
     */
    public function handle(Request $request): string
    {
        Activity::record($request, true);

        return 'Forged.';
    }
}
