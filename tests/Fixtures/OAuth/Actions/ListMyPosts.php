<?php

namespace Tests\Fixtures\OAuth\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Workbench\App\Models\Post;

/**
 * An account-level Read: the signed-in person's post titles.
 */
#[Expose]
final class ListMyPosts extends Action
{
    protected string $description = 'List the signed-in person\'s posts.';

    protected ?Effect $effect = Effect::Read;

    protected bool $tenantScoped = false;

    /**
     * The person's post titles.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'titles' => $schema->array()->items($schema->string())->required(),
        ];
    }

    /**
     * Any signed-in person.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * The person's post titles.
     *
     * @return array{titles: list<string>}
     */
    public function handle(ActionContext $context): array
    {
        return ['titles' => Post::query()->where('user_id', $context->actor()->getAuthIdentifier())->orderBy('id')->pluck('title')->all()];
    }
}
