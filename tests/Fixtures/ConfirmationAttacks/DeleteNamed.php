<?php

namespace Tests\Fixtures\ConfirmationAttacks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Tests\Fixtures\Actions\Trace;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/**
 * A Destructive agent action whose canonical input names the record by a value people can change, as a slug does:
 * the same validated input can name another record by the time the person confirms.
 */
#[Expose(agents: ['approvals'])]
final class DeleteNamed extends Action
{
    protected string $description = 'Delete one of the signed-in author\'s posts by its title.';

    protected ?Effect $effect = Effect::Destructive;

    protected bool $tenantScoped = false;

    /**
     * The title of the post to delete.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(200)->required(),
        ];
    }

    /**
     * The author must have a post with that title.
     */
    public function authorize(ActionContext $context, ValidatedInput $input): bool
    {
        return $this->post($context, $input) !== null;
    }

    /**
     * What the card shows of the post that title names now.
     */
    public function approvalSummary(ActionContext $context, ValidatedInput $input): array
    {
        $post = $this->post($context, $input);

        return ['Post' => $post?->title, 'Status' => $post?->status, 'Excerpt' => $post?->excerpt];
    }

    /**
     * Delete the post that title names now.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        Trace::record('DeleteNamed::handle');

        $this->post($context, $input)?->delete();

        return null;
    }

    /**
     * The author's post with that title.
     */
    private function post(ActionContext $context, ValidatedInput $input): ?Post
    {
        return $context->actor(User::class)->posts()->where('title', $input->string('title')->toString())->first();
    }
}
