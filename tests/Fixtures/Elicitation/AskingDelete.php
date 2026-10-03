<?php

namespace Tests\Fixtures\Elicitation;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\Post;

/**
 * A Destructive action that sets $askForMissing: it never asks. Its incomplete call is refused naming the fields, and
 * its complete call gets the card.
 */
#[Expose(agents: ['asking'])]
final class AskingDelete extends Action
{
    /**
     * Whether handle() ran.
     */
    public static bool $handled = false;

    protected string $description = 'Delete one of the signed-in author\'s posts.';

    protected ?Effect $effect = Effect::Destructive;

    protected bool $tenantScoped = false;

    protected bool $askForMissing = true;

    /**
     * The post, and two fields a form cannot hold.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post' => $schema->integer()->required(),
            'settings' => $schema->object(['notify' => $schema->boolean()]),
            'iban' => $schema->string(),
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
     * The post's title.
     */
    public function approvalSummary(ActionContext $context, ValidatedInput $input): array
    {
        return ['Post' => $context->find(Post::class, $input->integer('post'))->title];
    }

    /**
     * Delete the post.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        self::$handled = true;

        $context->find(Post::class, $input->integer('post'))->delete();

        return null;
    }
}
