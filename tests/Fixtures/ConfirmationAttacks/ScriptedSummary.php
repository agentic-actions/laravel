<?php

namespace Tests\Fixtures\ConfirmationAttacks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Tests\Fixtures\Actions\Trace;
use Workbench\App\Models\Post;

/**
 * A Destructive agent action whose card a test writes: approvalSummary() and approvalBinding() return, or throw, what
 * $summary and $binding give for the post.
 */
#[Expose(agents: ['approvals'])]
final class ScriptedSummary extends Action
{
    /**
     * The summary for the post; null gives its title.
     *
     * @var (Closure(Post): array<string, mixed>)|null
     */
    public static ?Closure $summary = null;

    /**
     * The binding for the post; null binds nothing.
     *
     * @var (Closure(Post): array<string, mixed>)|null
     */
    public static ?Closure $binding = null;

    protected string $description = 'Delete one of the signed-in author\'s posts.';

    protected ?Effect $effect = Effect::Destructive;

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
        return $context->find(Post::class, $input->integer('post'))->user()->is($context->actor());
    }

    /**
     * What the test scripted.
     */
    public function approvalSummary(ActionContext $context, ValidatedInput $input): array
    {
        $post = $context->find(Post::class, $input->integer('post'));

        return self::$summary === null ? ['Post' => $post->title] : (self::$summary)($post);
    }

    /**
     * What the test scripted.
     */
    public function approvalBinding(ActionContext $context, ValidatedInput $input): array
    {
        return self::$binding === null ? [] : (self::$binding)($context->find(Post::class, $input->integer('post')));
    }

    /**
     * Delete the post.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        Trace::record('ScriptedSummary::handle');

        $context->find(Post::class, $input->integer('post'))->delete();

        return null;
    }
}
