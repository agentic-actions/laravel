<?php

namespace Tests\Fixtures\Views;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use AgenticActions\Refusal;
use AgenticActions\Views\Column;
use AgenticActions\Views\ShowsTable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ValidatedInput;
use RuntimeException;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/**
 * The signed-in author's posts with one status, in the tenant when there is one: a table with input, including a key
 * named view. It records what each run received; its switches make shouldRegister(), authorize(), rules() and
 * handle() refuse, and drop a column.
 */
#[Expose(web: true, agents: ['views'])]
final class PostsByStatus extends Action implements ShowsTable
{
    /**
     * Whether shouldRegister() offers the action to the author.
     */
    public static bool $registered = true;

    /**
     * Whether authorize() allows the author.
     */
    public static bool $allowed = true;

    /**
     * Whether rules() accepts only "published".
     */
    public static bool $strict = false;

    /**
     * Whether handle() throws.
     */
    public static bool $crash = false;

    /**
     * The refusal handle() throws, if any.
     */
    public static ?Refusal $refusal = null;

    /**
     * Whether columns() declares the title alone.
     */
    public static bool $titleOnly = false;

    /**
     * The validated input, the fixed keys and whether a confirmation ticket came along, for each run of handle().
     *
     * @var list<array{input: array<string, mixed>, fixed: array<string, mixed>, ticket: bool}>
     */
    public static array $runs = [];

    protected string $description = 'The signed-in author\'s posts with one status.';

    protected ?Effect $effect = Effect::Read;

    /**
     * Put every switch back.
     */
    public static function reset(): void
    {
        self::$registered = true;
        self::$allowed = true;
        self::$strict = false;
        self::$crash = false;
        self::$refusal = null;
        self::$titleOnly = false;
        self::$runs = [];
    }

    /**
     * The status, and a view of the action's own.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->required(),
            'view' => $schema->string(),
        ];
    }

    /**
     * Only published posts while strict.
     */
    public function rules(ActionContext $context): array
    {
        return self::$strict ? ['status' => ['in:published']] : [];
    }

    /**
     * The status in lower case, as a person may type it in any.
     */
    public function prepareForValidation(array $input, ActionContext $context): array
    {
        return is_string($input['status'] ?? null) ? [...$input, 'status' => strtolower($input['status'])] : $input;
    }

    /**
     * The title and the status, or the title alone.
     */
    public function columns(): array
    {
        return self::$titleOnly
            ? [Column::text('title', 'Title')]
            : [Column::text('title', 'Title'), Column::text('status', 'Status')];
    }

    /**
     * Offered while registered.
     */
    public function shouldRegister(ActionContext $context): bool
    {
        return self::$registered;
    }

    /**
     * A signed-in author, while allowed.
     */
    public function authorize(ActionContext $context): bool
    {
        return self::$allowed && $context->actor !== null;
    }

    /**
     * The author's posts with the status, oldest first.
     *
     * @return Builder<Post>
     */
    public function handle(ActionContext $context, ValidatedInput $input): Builder
    {
        if (self::$crash) {
            throw new RuntimeException('The posts could not be read.');
        }

        if (self::$refusal !== null) {
            throw self::$refusal;
        }

        self::$runs[] = ['input' => $input->all(), 'fixed' => $context->fixed, 'ticket' => $context->approval !== null];

        return Post::query()
            ->where('user_id', $context->actor(User::class)->getKey())
            ->where('status', (string) $input->string('status'))
            ->when($context->tenant, fn (Builder $query, Model $team): Builder => $query->where('team_id', $team->getKey()))
            ->orderBy('id');
    }
}
