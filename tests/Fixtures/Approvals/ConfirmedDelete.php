<?php

namespace Tests\Fixtures\Approvals;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Tests\Fixtures\Actions\Trace;
use Workbench\App\Models\Post;

/**
 * A Destructive action offered to agents after a confirmation, and on the web: it deletes one of the author's posts,
 * checks the post in an input-taking authorize(), and builds its card from the record.
 */
#[Expose(web: true, agents: ['approvals'])]
final class ConfirmedDelete extends Action
{
    /**
     * The context the last handle() ran with.
     */
    public static ?ActionContext $handled = null;

    /**
     * What ActionContext::current() read in the last handle().
     */
    public static ?ActionContext $current = null;

    protected string $description = 'Delete one of the signed-in author\'s posts.';

    protected ?Effect $effect = Effect::Destructive;

    protected array $touches = ['posts'];

    protected bool $tenantScoped = false;

    /**
     * The post to delete.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post' => $schema->integer()->required(),
        ];
    }

    /**
     * Only the post's author.
     */
    public function authorize(ActionContext $context, ValidatedInput $input): bool
    {
        Trace::record('ConfirmedDelete::authorize');

        return $context->find(Post::class, $input->integer('post'))->user()->is($context->actor());
    }

    /**
     * The card's sentence.
     */
    public function approvalReason(ActionContext $context): string
    {
        return 'Delete this post? This cannot be undone.';
    }

    /**
     * The post's title, status and excerpt, from the record authorize() allowed.
     */
    public function approvalSummary(ActionContext $context, ValidatedInput $input): array
    {
        $post = $context->find(Post::class, $input->integer('post'));

        return ['Post' => $post->title, 'Status' => $post->status, 'Excerpt' => $post->excerpt];
    }

    /**
     * Delete the post.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        Trace::record('ConfirmedDelete::handle');

        self::$handled = $context;
        self::$current = ActionContext::current();

        $context->find(Post::class, $input->integer('post'))->delete();

        return null;
    }
}
