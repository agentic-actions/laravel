<?php

namespace Workbench\App\Ai\Tools;

use AgenticActions\Contracts\DescribesActivity;
use AgenticActions\Streaming\Activity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Workbench\App\Models\User;

/**
 * A hand-written laravel/ai tool with a copilot row, as docs/copilot.md writes it.
 */
final class CountDrafts implements DescribesActivity, Tool
{
    /**
     * Build the tool for one author.
     */
    public function __construct(private User $user) {}

    /**
     * What the tool does, for the model.
     */
    public function description(): string
    {
        return 'Count the author\'s draft posts.';
    }

    /**
     * The tool takes no input.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * The row's label while the tool runs, and after it succeeded.
     */
    public function activityLabel(bool $finished): ?string
    {
        return $finished ? __('Counted your drafts') : __('Counting your drafts…');
    }

    /**
     * Count the drafts, and mark the row done.
     */
    public function handle(Request $request): string
    {
        $count = $this->user->posts()->where('status', 'draft')->count();

        Activity::record($request, ok: true);

        return "The author has {$count} drafts.";
    }
}
