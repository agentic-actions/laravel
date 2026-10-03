<?php

namespace Tests\Fixtures\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * A hand-written tool whose name collides with the create-note action.
 */
final class CreateNoteTool implements Tool
{
    /**
     * The colliding name.
     */
    public function name(): string
    {
        return 'create-note';
    }

    /**
     * What the tool does.
     */
    public function description(): string
    {
        return 'Create a note by hand.';
    }

    /**
     * Answer without doing anything.
     */
    public function handle(Request $request): string
    {
        return 'Hand-written.';
    }

    /**
     * The tool's input.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
        ];
    }
}
