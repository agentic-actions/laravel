<?php

namespace Tests\Fixtures\Http;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\User;

/**
 * A Read with input, so a GET's query string has something to carry: a string, an integer, a boolean and a list.
 */
#[Expose(web: true)]
final class SearchNotes extends Action
{
    protected string $description = 'Search the signed-in author\'s notes.';

    protected ?Effect $effect = Effect::Read;

    protected bool $tenantScoped = false;

    /**
     * What to look for.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->nullable(),
            'limit' => $schema->integer()->min(1)->max(50),
            'drafts' => $schema->boolean(),
            'ids' => $schema->array()->items($schema->integer()),
        ];
    }

    /**
     * The matching notes, id and title only, and what the input arrived as.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'posts' => $schema->array()->items($schema->object([
                'id' => $schema->integer()->required(),
                'title' => $schema->string()->required(),
            ]))->required(),
            'input' => $schema->object([
                'title' => $schema->string()->nullable(),
                'limit' => $schema->integer(),
                'drafts' => $schema->boolean(),
                'ids' => $schema->array()->items($schema->integer()),
            ]),
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
     * The author's notes that match.
     *
     * @return array<string, mixed>
     */
    public function handle(ActionContext $context, ValidatedInput $input): array
    {
        $query = $context->actor(User::class)->posts()->orderBy('id');

        if ($input->filled('title')) {
            $query->where('title', $input->string('title')->toString());
        }

        if ($input->has('drafts')) {
            $query->where('status', $input->boolean('drafts') ? '=' : '!=', 'draft');
        }

        if ($input->filled('ids')) {
            $query->whereKey($input->array('ids'));
        }

        return [
            'posts' => $query->limit($input->integer('limit', 10))->get(),
            'input' => $input->all(),
        ];
    }
}
