<?php

namespace Tests\Fixtures\Elicitation;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * A Write behind a bare #[Expose] whose publish date and status the route, the CLI and the app's own code may leave
 * out, and a model's call must give: requiredForAgents() names them, and it asks the person for what a call left out.
 */
#[Expose]
final class AskingRequired extends Action
{
    /**
     * The validated input the last handle() ran with.
     *
     * @var array<string, mixed>|null
     */
    public static ?array $handled = null;

    protected string $description = 'Plan a post for the signed-in author.';

    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    protected bool $askForMissing = true;

    /**
     * The post's title, and a publish date and status every caller but a model may leave out.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->title('Title')->max(120)->required(),
            'publish_on' => $schema->string()->title('Publish on')->format('date')->nullable(),
            'status' => $schema->string()->title('Status')->enum(['draft', 'published']),
        ];
    }

    /**
     * A model's call gives the publish date and the status.
     */
    public function requiredForAgents(): array
    {
        return ['publish_on', 'status'];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Record the run.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        self::$handled = $input->all();

        return null;
    }
}
