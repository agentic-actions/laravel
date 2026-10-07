<?php

namespace Tests\Fixtures\Initialize;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Illuminate\Support\ValidatedInput;
use Tests\Fixtures\Actions\Trace;
use Workbench\App\Models\User;

/**
 * A post's share link, made the first time anyone reads it: initialize() adds the row when the post has none, and
 * handle() only reads it. It asks the person for a post a model's call leaves out, and records its hooks in Trace.
 */
#[Expose]
final class ShowShareLink extends Action
{
    protected string $description = 'Show the link a post of the signed-in author is shared by.';

    protected ?Effect $effect = Effect::Read;

    protected bool $tenantScoped = false;

    protected bool $askForMissing = true;

    /**
     * The table initialize() adds the link to.
     *
     * @var list<string>
     */
    protected array $initializes = ['share_links'];

    /**
     * The post.
     */
    public function schema(JsonSchema $schema): array
    {
        return ['post' => $schema->integer()->title('Post')->required()];
    }

    /**
     * The link's token.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['token' => $schema->string()->required()];
    }

    /**
     * Record that validation is about to run.
     */
    public function rules(ActionContext $context): array
    {
        Trace::record('rules');

        return [];
    }

    /**
     * A signed-in author before the input is read, then one of their own posts.
     */
    public function authorize(ActionContext $context, ?ValidatedInput $input = null): bool
    {
        Trace::record($input === null ? 'authorize' : 'authorize with input');

        return $context->actor !== null
            && ($input === null || $context->actor(User::class)->posts()->whereKey($input->integer('post'))->exists());
    }

    /**
     * Add the post's link when it has none.
     */
    public function initialize(ValidatedInput $input): void
    {
        Trace::record('initialize');

        ShareLink::query()->firstOrCreate(['post_id' => $input->integer('post')], fn (): array => ['token' => Str::random(32)]);
    }

    /**
     * The post's link.
     *
     * @return array{token: string}
     */
    public function handle(ValidatedInput $input): array
    {
        Trace::record('handle');

        return ['token' => ShareLink::query()->where('post_id', $input->integer('post'))->sole()->token];
    }
}
