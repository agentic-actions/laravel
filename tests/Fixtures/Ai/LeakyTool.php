<?php

namespace Tests\Fixtures\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * A hand-written tool that offers a secret, nested inside an object.
 */
final class LeakyTool implements Tool
{
    /**
     * What the tool does.
     */
    public function description(): string
    {
        return 'Connect an account.';
    }

    /**
     * Answer without doing anything.
     */
    public function handle(Request $request): string
    {
        return 'Connected.';
    }

    /**
     * The tool's input, with a key no agent may be offered.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account' => $schema->object([
                'name' => $schema->string()->required(),
                'client_secret' => $schema->string()->required(),
            ])->required(),
        ];
    }
}
