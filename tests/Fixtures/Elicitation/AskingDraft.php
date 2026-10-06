<?php

namespace Tests\Fixtures\Elicitation;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Ask;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\User;

/**
 * A Write that asks the person for what a model's call left out: it confirms the title, shows the body as several
 * lines and titles the two statuses. It also sends its validation messages to a model, and records what it ran with.
 */
#[Expose(agents: ['asking'])]
final class AskingDraft extends Action
{
    /**
     * The validated input the last handle() ran with.
     *
     * @var array<string, mixed>|null
     */
    public static ?array $handled = null;

    /**
     * The context the last handle() ran with.
     */
    public static ?ActionContext $context = null;

    /**
     * What ActionContext::current() read in the last handle().
     */
    public static ?ActionContext $current = null;

    /**
     * Whether authorize() allows the actor.
     */
    public static bool $allowed = true;

    /**
     * Reset what the last test recorded and allowed.
     */
    public static function reset(): void
    {
        self::$handled = null;
        self::$context = null;
        self::$current = null;
        self::$allowed = true;
    }

    protected string $description = 'Draft a post for the signed-in author.';

    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    protected bool $validationMessagesToModel = true;

    protected bool $askForMissing = true;

    /**
     * The post's fields.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->title('Title')->min(1)->max(120)->required(),
            'body' => $schema->string()->title('Body')->max(5000)->required(),
            'status' => $schema->string()->title('Status')->enum(['draft', 'published'])->required(),
            'excerpt' => $schema->string()->title('Excerpt')->max(200)->nullable(),
        ];
    }

    /**
     * Any signed-in author, while allowed.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null && self::$allowed;
    }

    /**
     * The form's sentence, the title for review, the body as several lines and the statuses' titles.
     */
    public function ask(Ask $ask, ActionContext $context): Ask
    {
        return $ask
            ->message('A few details for your post.')
            ->confirm('title')
            ->textarea('body')
            ->choices('status', ['draft' => 'Draft', 'published' => 'Published']);
    }

    /**
     * Save the draft.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        self::$handled = $input->all();
        self::$context = $context;
        self::$current = ActionContext::current();

        $context->actor(User::class)->posts()->create($input->only(['title', 'body', 'status', 'excerpt']));

        return null;
    }
}
