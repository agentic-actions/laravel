<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Attributes\Rest;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * A Write with a REST method for each other effect, a path parameter schema() lacks, a bind entry for no parameter,
 * created on a PATCH and a location naming nothing.
 */
#[Expose(web: true)]
#[Rest('GET', 'posts/{post}')]
#[Rest('DELETE', 'posts/{post}/draft')]
#[Rest('PATCH', 'posts/{post}/{section}', bind: ['post' => 'post', 'chapter' => 'chapter_id'], created: true)]
#[Rest('POST', 'posts', created: true, location: 'posts/{slug}')]
#[Rest('PUT', 'posts/{post}/title', location: 'posts/{post}')]
final class RestWrongMethods extends Action
{
    protected string $description = 'Rename a post.';

    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    /**
     * The post.
     */
    public function schema(JsonSchema $schema): array
    {
        return ['post' => $schema->integer()->required()];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Nothing.
     */
    public function handle(): void {}
}
