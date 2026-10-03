<?php

namespace Tests\Fixtures\Streaming;

use AgenticActions\Contracts\DescribesActivity;
use AgenticActions\Streaming\Activity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * A hand-written tool that keeps its last argument and shows it in its labels, so a label read after its own call
 * would show that call's argument.
 */
final class StatefulTool implements DescribesActivity, Tool
{
    /**
     * The word of the last call this instance ran.
     */
    private ?string $word = null;

    /**
     * Labels that name the last word this instance saw.
     */
    public function activityLabel(bool $finished): ?string
    {
        $about = $this->word === null ? '' : " about {$this->word}";

        return $finished ? "Thought{$about}" : "Thinking{$about}…";
    }

    /**
     * What the tool does.
     */
    public function description(): string
    {
        return 'Think about a word.';
    }

    /**
     * The word.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'word' => $schema->string()->required(),
        ];
    }

    /**
     * Keep the word and report success.
     */
    public function handle(Request $request): string
    {
        $this->word = (string) $request['word'];

        Activity::record($request, true);

        return 'Thought.';
    }
}
