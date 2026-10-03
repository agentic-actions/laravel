<?php

namespace Tests\Fixtures\McpFormAttacks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * An asking Write behind a bare #[Expose] whose requiredForAgents() names a summary agents are offered, whose rules()
 * let it be absent, and a flag only rules() checks, which agents are never offered: the rules must not let a model's
 * call leave either out, and naming the flag must not open it to a model or a form.
 */
#[Expose]
final class HiddenRequirement extends Action
{
    /**
     * Whether handle() ran.
     */
    public static bool $handled = false;

    /**
     * The summary's rules() entry, in whatever form Laravel reads.
     */
    public static mixed $summaryRules = 'sometimes|max:200';

    protected string $description = 'Publish a post for the signed-in author.';

    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    protected bool $askForMissing = true;

    /**
     * The post's title and an optional summary.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->title('Title')->required(),
            'summary' => $schema->string()->title('Summary'),
        ];
    }

    /**
     * The summary's rules, and a flag only the app's own code sets.
     */
    public function rules(ActionContext $context): array
    {
        return ['summary' => self::$summaryRules, 'reviewed' => ['sometimes', 'accepted']];
    }

    /**
     * The summary, and the flag, named by mistake.
     */
    public function requiredForAgents(): array
    {
        return ['summary', 'reviewed'];
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
        self::$handled = true;

        return null;
    }
}
