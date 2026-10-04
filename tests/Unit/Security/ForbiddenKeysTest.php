<?php

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use AgenticActions\Exceptions\MisconfiguredExposure;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Security\ForbiddenKeys;
use AgenticActions\Views\Column;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Views\Invalid\BadColumns;
use Tests\Fixtures\Views\PostStats;

/**
 * A serialized node for a schema written with the JSON Schema factory.
 *
 * @param  Closure(JsonSchemaTypeFactory): array<string, Type>  $schema
 * @return array<string, mixed>
 */
function schemaNode(Closure $schema): array
{
    return (new ObjectType($schema(new JsonSchemaTypeFactory)))->toArray();
}

/**
 * The sentence advertisable() uses for a forbidden input key.
 */
function inputMessage(string $class, string $path, string $reason): string
{
    return "{$class}: agents cannot be offered input [{$path}]: it {$reason}. The tool is left out. Remove the key from schema(), or follow the \"Strict agent schemas (no ids)\" recipe (https://agentic-actions.com/recipes#strict-agent-schemas-no-ids): agentSchema() plus fromAgent().";
}

it('catches the default globs, case-insensitively, at any depth', function () {
    $node = schemaNode(fn ($s) => [
        'client_secret' => $s->string(),
        'Password_Confirmation' => $s->string(),
        'title' => $s->string(),
        'auth' => $s->object(['refresh_token' => $s->string(), 'label' => $s->string()]),
        'keys' => $s->array()->items($s->object(['API_KEY' => $s->string()])),
    ]);

    expect(app(ForbiddenKeys::class)->inInput($node, []))
        ->toBe(['client_secret', 'Password_Confirmation', 'auth.refresh_token', 'keys.*.API_KEY']);
});

it('always forbids route parameters, the tenant parameter and the tenant foreign key', function () {
    $this->useTeamTenancy();
    config(['agentic-actions.agents.forbidden_keys' => []]);

    $node = schemaNode(fn ($s) => [
        'note' => $s->integer(),
        'team' => $s->string(),
        'team_id' => $s->integer(),
        'meta' => $s->object(['Team_Id' => $s->integer()]),
        'title' => $s->string(),
    ]);

    expect(app(ForbiddenKeys::class)->inInput($node, ['note']))->toBe(['note', 'team', 'team_id', 'meta.Team_Id']);
});

it('forbids the tenant parameter even without a tenant model, but no foreign key', function () {
    $node = schemaNode(fn ($s) => ['tenant' => $s->string(), 'team_id' => $s->integer()]);

    expect(app(ForbiddenKeys::class)->inInput($node, []))->toBe(['tenant']);
});

it('allows id-shaped keys and reports them', function () {
    $node = schemaNode(fn ($s) => [
        'id' => $s->integer(),
        'post_id' => $s->integer(),
        'UUID' => $s->string(),
        'items' => $s->array()->items($s->object(['owner_id' => $s->integer()])),
        'idea' => $s->string(),
    ]);

    expect(app(ForbiddenKeys::class)->inInput($node, []))->toBe([])
        ->and(app(ForbiddenKeys::class)->idShaped($node))->toBe(['id', 'post_id', 'UUID', 'items.*.owner_id']);
});

it('checks output keys against forbidden_output_keys only', function () {
    $node = schemaNode(fn ($s) => ['id' => $s->integer(), 'token' => $s->string(), 'meta' => $s->object(['internal_note' => $s->string()])]);

    expect(app(ForbiddenKeys::class)->inOutput($node))->toBe([]);

    config(['agentic-actions.agents.forbidden_output_keys' => ['internal_*']]);

    expect(app(ForbiddenKeys::class)->inOutput($node))->toBe(['meta.internal_note']);
});

it('reads the parameters of every route whose controller is the class', function () {
    $this->mountRoutes(function (): void {
        Route::post('notes/{note}/secret', SecretNote::class)->name('notes.secret');
        Route::post('boards/{board}/secret', SecretNote::class);
    });

    expect(app(ForbiddenKeys::class)->routeParameters(SecretNote::class))->toBe(['note', 'board'])
        ->and(app(ForbiddenKeys::class)->routeParameters(CreateNote::class))->toBe([]);
});

it('advertises an action with no forbidden key', function () {
    expect(app(ForbiddenKeys::class)->advertisable(ClassExposure::of(CreateNote::class), ActionContext::agent(null)))->toBeTrue();
});

it('names the pattern in its message, and the recipe', function () {
    $entry = ClassExposure::of(SecretNote::class);

    expect(fn () => app(ForbiddenKeys::class)->advertisable($entry, ActionContext::agent(null)))
        ->toThrow(MisconfiguredExposure::class, inputMessage(SecretNote::class, 'client_secret', 'matches agents.forbidden_keys pattern "*secret*"'));
});

it('names the route, the tenant parameter and the foreign key in its messages', function () {
    $this->useTeamTenancy();
    config(['agentic-actions.agents.forbidden_keys' => []]);

    $this->mountRoutes(fn () => Route::post('notes/{note}/secret', SecretNote::class)->name('notes.secret'));

    try {
        app(ForbiddenKeys::class)->advertisable(ClassExposure::of(SecretNote::class), ActionContext::agent(null));
        $this->fail('No exception.');
    } catch (MisconfiguredExposure $exception) {
        expect($exception->getMessage())->toBe(implode("\n", [
            inputMessage(SecretNote::class, 'note', 'is a route parameter of notes.secret'),
            inputMessage(SecretNote::class, 'team', 'is the tenant parameter'),
            inputMessage(SecretNote::class, 'team_id', "is the tenant model's foreign key"),
        ]));
    }
});

it('names the route by its URI when it has no name', function () {
    config(['agentic-actions.agents.forbidden_keys' => []]);

    $this->mountRoutes(fn () => Route::post('notes/{note}/secret', SecretNote::class));

    expect(fn () => app(ForbiddenKeys::class)->advertisable(ClassExposure::of(SecretNote::class), ActionContext::agent(null)))
        ->toThrow(MisconfiguredExposure::class, 'it is a route parameter of notes/{note}/secret.');
});

it('refuses forbidden output keys and file fields', function () {
    config(['agentic-actions.agents.forbidden_output_keys' => ['*secret*']]);

    try {
        app(ForbiddenKeys::class)->advertisable(ClassExposure::of(LeakyOutputNote::class), ActionContext::agent(null));
        $this->fail('No exception.');
    } catch (MisconfiguredExposure $exception) {
        expect($exception->getMessage())->toBe(implode("\n", [
            LeakyOutputNote::class.': agents cannot receive output [secret]: it matches agents.forbidden_output_keys. The tool is left out. Remove the key from outputSchema().',
            LeakyOutputNote::class.': agents cannot send the file field [photo]. The tool is left out. Give agents an agentSchema() without it, following the "Strict agent schemas (no ids)" recipe (https://agentic-actions.com/recipes#strict-agent-schemas-no-ids).',
        ]));
    }
});

it('offers a table whatever its own keys match, and refuses a column that matches', function () {
    config(['agentic-actions.agents.forbidden_output_keys' => ['*key*', 'label', 'type', 'columns', 'rows', 'chart', 'caption', 'truncated']]);

    expect(app(ForbiddenKeys::class)->advertisable(ClassExposure::of(PostStats::class), ActionContext::agent(null)))->toBeTrue();

    config(['agentic-actions.agents.forbidden_output_keys' => ['words']]);

    expect(fn () => app(ForbiddenKeys::class)->advertisable(ClassExposure::of(PostStats::class), ActionContext::agent(null)))
        ->toThrow(MisconfiguredExposure::class, PostStats::class.': agents cannot receive output [words]: it matches agents.forbidden_output_keys. The tool is left out. Remove the column from columns().');
});

it('leaves out a table whose columns() cannot be shown, naming why, and the others stay', function () {
    BadColumns::$columns = fn (): array => [Column::text('Title', 'Title')];

    expect(fn () => app(ForbiddenKeys::class)->advertisable(ClassExposure::of(BadColumns::class), ActionContext::agent(null)))
        ->toThrow(MisconfiguredExposure::class, BadColumns::class.': agents cannot be offered it: '.BadColumns::class.': The column key [Title] must be 1 to 64 lower-case letters, digits or underscores, starting with a letter. The tool is left out.')
        ->and(app(ForbiddenKeys::class)->advertisable(ClassExposure::of(PostStats::class), ActionContext::agent(null)))->toBeTrue();

    BadColumns::$columns = null;
});

it('reports once an hour and leaves the tool out outside tests and local', function () {
    Exceptions::fake();
    $this->app['env'] = 'production';

    $entry = ClassExposure::of(SecretNote::class);

    try {
        expect(app(ForbiddenKeys::class)->advertisable($entry, ActionContext::agent(null)))->toBeFalse()
            ->and(app(ForbiddenKeys::class)->advertisable($entry, ActionContext::agent(null)))->toBeFalse();
    } finally {
        // Put the environment back before the application is torn down.
        $this->app['env'] = 'testing';
    }

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (MisconfiguredExposure $exception): bool => str_contains($exception->getMessage(), '[client_secret]'));
});

it('throws when running locally', function () {
    $this->app['env'] = 'local';

    try {
        expect(fn () => app(ForbiddenKeys::class)->advertisable(ClassExposure::of(SecretNote::class), ActionContext::agent(null)))
            ->toThrow(MisconfiguredExposure::class);
    } finally {
        $this->app['env'] = 'testing';
    }
});

/**
 * Offers agents keys they may never see.
 */
#[Expose]
final class SecretNote extends Action
{
    protected string $description = 'Store a secret.';

    protected ?Effect $effect = Effect::Write;

    public function schema(JsonSchema $schema): array
    {
        return [
            'note' => $schema->integer(),
            'team' => $schema->string(),
            'team_id' => $schema->integer(),
            'client_secret' => $schema->string(),
            'title' => $schema->string(),
        ];
    }
}

/**
 * Returns a key agents may not receive and takes a file.
 */
#[Expose]
final class LeakyOutputNote extends Action
{
    protected string $description = 'Upload a photo.';

    protected ?Effect $effect = Effect::Write;

    public function schema(JsonSchema $schema): array
    {
        return ['photo' => $schema->string()->format('binary')];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->required(), 'secret' => $schema->string()];
    }
}
