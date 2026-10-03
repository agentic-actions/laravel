<?php

namespace Tests\Fixtures\Streaming;

use AgenticActions\Contracts\DescribesActivity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;

/**
 * A hand-written tool with a row that throws: a RuntimeException, or a ValidationException when the hook says so.
 */
final class ThrowingTool implements DescribesActivity, Tool
{
    /**
     * When true, handle() throws a ValidationException instead.
     */
    public static bool $validation = false;

    /**
     * Fixed labels.
     */
    public function activityLabel(bool $finished): ?string
    {
        return $finished ? 'Checked' : 'Checking…';
    }

    /**
     * What the tool does.
     */
    public function description(): string
    {
        return 'Check something.';
    }

    /**
     * No input.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * Throw.
     */
    public function handle(Request $request): string
    {
        if (self::$validation) {
            throw ValidationException::withMessages(['word' => 'The word must be longer.']);
        }

        throw new RuntimeException('CANARY-EXCEPTION');
    }
}
