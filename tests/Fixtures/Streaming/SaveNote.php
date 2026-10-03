<?php

namespace Tests\Fixtures\Streaming;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use RuntimeException;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/**
 * A Write with its own labels, touches and a followed link. handle() keeps the title on the instance and the finished
 * label shows it when set, so a label read after the call would show the title.
 */
#[Expose(agents: ['stream'])]
final class SaveNote extends Action
{
    /**
     * When true, activityLabel() throws.
     */
    public static bool $throwingLabel = false;

    protected string $description = 'Save a note for the signed-in author.';

    protected ?Effect $effect = Effect::Write;

    protected array $touches = ['notes'];

    protected bool $followLink = true;

    /**
     * The title of the call this instance ran.
     */
    private ?string $title = null;

    /**
     * The note's title.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(20)->required(),
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
     * Save the note and keep its title.
     */
    public function handle(ActionContext $context, ValidatedInput $input): Post
    {
        $this->title = $input->string('title')->toString();

        return $context->actor(User::class)->posts()->create(['title' => $this->title, 'body' => 'x', 'status' => 'draft']);
    }

    /**
     * The saved note's own page.
     */
    public function redirectTo(mixed $result, ActionContext $context): ?string
    {
        return $result instanceof Post ? "/notes/{$result->id}" : null;
    }

    /**
     * Its own labels; the finished one names the title when this instance ran a call.
     */
    public function activityLabel(ActionContext $context, bool $finished): ?string
    {
        if (self::$throwingLabel) {
            throw new RuntimeException('The label failed.');
        }

        return match (true) {
            ! $finished => 'Saving your note…',
            $this->title !== null => "Saved {$this->title}",
            default => 'Note saved',
        };
    }
}
