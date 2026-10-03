<?php

namespace Tests\Fixtures\Views;

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
 * The signed-in author's posts as a table: title, words and share of all their words. Each row also carries the author,
 * a record, in a text column, and an undeclared secret, neither of which may leave the server. A bare #[Expose], so it
 * opens the web and MCP without laravel/ai too, and the default toolset with it.
 */
#[Expose]
final class PostStats extends Action implements ShowsTable
{
    protected string $description = 'Words per post of the signed-in author.';

    protected ?Effect $effect = Effect::Read;

    protected bool $tenantScoped = false;

    /**
     * Title, words, share and author.
     */
    public function columns(): array
    {
        return [
            Column::text('title', __('Title')),
            Column::integer('words', __('Words')),
            Column::percent('share', __('Share')),
            Column::text('author', __('Author')),
        ];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * One row per post, oldest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function handle(ActionContext $context): Collection
    {
        $author = $context->actor(User::class);
        $posts = Post::query()->where('user_id', $author->getKey())->orderBy('id')->get();
        $total = max(1, $posts->sum(fn (Post $post): int => str_word_count($post->body)));

        return $posts->map(fn (Post $post): array => [
            'title' => $post->title,
            'words' => str_word_count($post->body),
            'share' => str_word_count($post->body) / $total,
            'author' => $author,
            'secret' => 'CANARY-SECRET',
        ]);
    }
}
