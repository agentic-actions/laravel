<?php

namespace Workbench\App\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Ask;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Illuminate\Validation\ValidationException;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/**
 * A Write that asks, as docs/asking.md shows: served on the web under teams/{team}, and offered to TeamAssistant. When
 * the model's call leaves fields out, the member fills them in a form in the chat, with the title shown for review.
 */
#[Expose(web: true, agents: ['team'])]
final class DraftTeamPost extends Action
{
    protected string $description = 'Draft a post in the current team.';

    protected ?Effect $effect = Effect::Write;

    protected array $touches = ['posts'];

    protected bool $validationMessagesToModel = true;

    protected bool $askForMissing = true;

    /**
     * The post's fields, with the titles the form shows.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->title(__('Title'))->min(1)->max(120)->required(),
            'body' => $schema->string()->title(__('Body'))->max(5000)->required(),
            'status' => $schema->string()->title(__('Status'))->enum(['draft', 'published'])->required(),
            'excerpt' => $schema->string()->title(__('Excerpt'))->max(200)->nullable()->description(__('One line for listings.')),
        ];
    }

    /**
     * What the caller gets back.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
        ];
    }

    /**
     * Any signed-in member. Membership of the team is checked before this runs.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor instanceof User;
    }

    /**
     * The form's sentence, the title for review, the body as several lines and the statuses' titles.
     */
    public function ask(Ask $ask, ActionContext $context): Ask
    {
        return $ask
            ->message(__('A few details for your post.'))
            ->confirm('title')
            ->textarea('body')
            ->choices('status', ['draft' => __('Draft'), 'published' => __('Published')]);
    }

    /**
     * Save the post in the team, as the member. A team has one post per title; the message naming it goes to the
     * person, and a model hears only the field when the title came from a form.
     */
    public function handle(ActionContext $context, ValidatedInput $input): Post
    {
        $team = $context->tenant(Team::class);
        $title = $input->string('title')->toString();

        if ($team->posts()->where('title', $title)->exists()) {
            throw ValidationException::withMessages(['title' => __('The team already has a post titled ":title".', ['title' => $title])]);
        }

        return $context->actor(User::class)->posts()->create([...$input->only(['title', 'body', 'status', 'excerpt']), 'team_id' => $team->getKey()]);
    }
}
