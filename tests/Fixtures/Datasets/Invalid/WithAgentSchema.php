<?php

namespace Tests\Fixtures\Datasets\Invalid;

use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Datasets\Dataset;
use AgenticActions\Datasets\Dimension;
use AgenticActions\Datasets\Measure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Workbench\App\Models\Post;

/**
 * A dataset that declares the schemas the package generates. Listed by its own tests only.
 */
#[Expose(agents: ['datasets'])]
final class WithAgentSchema extends Dataset
{
    protected string $description = 'Posts, with schemas of its own.';

    protected string $model = Post::class;

    protected bool $tenantScoped = false;

    /**
     * A title.
     */
    public function dimensions(): array
    {
        return [Dimension::text('title', 'Title', 'title')];
    }

    /**
     * A count.
     */
    public function measures(): array
    {
        return [Measure::count('posts', 'Posts')];
    }

    /**
     * An agent's own vocabulary.
     */
    public function agentSchema(JsonSchema $schema): ?array
    {
        return ['question' => $schema->string()];
    }

    /**
     * An output of its own.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['answer' => $schema->string()];
    }

    /**
     * Anyone.
     */
    public function authorize(ActionContext $context): bool
    {
        return true;
    }
}
