<?php

namespace Workbench\App\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use AgenticActions\Views\Column;
use AgenticActions\Views\ShowsTable;
use Illuminate\Support\Collection;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/**
 * The table of docs/data.md for the blog: the words of each of the signed-in author's posts, and the post's share of
 * all their words. In the copilot, the person sees the rows, which the page draws as a table and a bar chart, and the
 * model reads a short copy.
 */
#[Expose(web: true, agents: ['default'])]
final class PostStats extends Action implements ShowsTable
{
    protected string $description = 'Words per post of the signed-in author, and each post\'s share of all their words.';

    protected ?Effect $effect = Effect::Read;

    /**
     * Only matters once config('agentic-actions.tenant.model') is set: an author's posts, in every team.
     */
    protected bool $tenantScoped = false;

    /**
     * The title, then the words and the share, so the page draws words as a bar per post.
     */
    public function columns(): array
    {
        return [
            Column::text('title', __('Title')),
            Column::integer('words', __('Words'))->description('Words in the post\'s body'),
            Column::percent('share', __('Share'))->description('The post\'s share of all the author\'s words'),
        ];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor instanceof User;
    }

    /**
     * One row per post, oldest first.
     *
     * @return Collection<int, array{title: string, words: int, share: float}>
     */
    public function handle(ActionContext $context): Collection
    {
        $posts = $context->actor(User::class)->posts()->oldest('id')->get();
        $total = max(1, $posts->sum(fn (Post $post): int => str_word_count($post->body)));

        return $posts->toBase()->map(fn (Post $post): array => [
            'title' => $post->title,
            'words' => str_word_count($post->body),
            'share' => str_word_count($post->body) / $total,
        ]);
    }
}
