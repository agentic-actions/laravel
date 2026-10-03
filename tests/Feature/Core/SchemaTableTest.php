<?php

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Pipeline\Door;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Runner;
use AgenticActions\Schema\SchemaReader;
use AgenticActions\Surface;
use AgenticActions\TypeScript\TypeWriter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Illuminate\Support\ValidatedInput;
use Tests\Fixtures\Queue\Queued;
use Workbench\App\Models\User;

/*
 * One field per row, through the real Runner on six surfaces and the real TypeWriter: what handle() receives (null
 * when the input is refused), and the property the generated types write. The generated type never admits a value
 * the server refuses, but for an empty or blank string where null is not allowed, which a TypeScript string cannot
 * rule out; the server may take more, such as a form's "12" for a number.
 */

it('agrees with the generated types on omitted keys, null, empty strings, list items, finite numbers and defaults', function (Closure $field, array $input, ?array $received, string $typescript) {
    TableField::$field = $field;
    $actor = User::factory()->create();

    $contexts = [
        ActionContext::system(),
        ActionContext::http($actor),
        ActionContext::console($actor, null, 'en', null),
        ActionContext::agent($actor),
        ActionContext::queued(Surface::Http, $actor, null, 'en', [], null, null),
        ActionContext::mcp(Queued::tokenUser(['actions:read'], $actor), null),
    ];

    foreach ($contexts as $context) {
        $outcome = app(Runner::class)->run(ClassExposure::of(TableField::class), $input, $context, Door::InProcess);

        expect($outcome->kind())->toBe($received === null ? OutcomeKind::Invalid : OutcomeKind::Ok)
            ->and($received === null ? null : $outcome->result())->toBe($received);
    }

    $written = (string) preg_replace('/\s+/', ' ', trim((new TypeWriter)->object(app(SchemaReader::class)->input(new TableField)), "{} \n"));
    $value = $input['v'] ?? null;
    $admits = match (true) {
        array_filter(Arr::flatten($input), fn (mixed $item): bool => is_string($item) && trim($item) === '') !== [] => false,
        ! array_key_exists('v', $input) => str_starts_with($written, 'v?:'),
        $value === null => str_ends_with($written, '| null;'),
        is_string($value) => str_contains($written, ': string'),
        is_float($value) && ! is_finite($value) => false,
        is_array($value) && in_array(null, $value, true) => str_contains($written, '| null>'),
        default => true,
    };

    expect($written)->toBe($typescript.';')
        ->and($admits && $received === null)->toBeFalse();
})->with([
    'optional, omitted' => [fn ($s) => $s->integer(), [], [], 'v?: number'],
    'optional, null' => [fn ($s) => $s->integer(), ['v' => null], null, 'v?: number'],
    'optional, an empty string' => [fn ($s) => $s->integer(), ['v' => ''], null, 'v?: number'],
    'required, omitted' => [fn ($s) => $s->integer()->required(), [], null, 'v: number'],
    'required with a default, omitted' => [fn ($s) => $s->integer()->default(1)->required(), [], null, 'v: number'],
    'optional with a default, omitted' => [fn ($s) => $s->integer()->default(1), [], [], 'v?: number'],
    'nullable, null' => [fn ($s) => $s->integer()->nullable(), ['v' => null], ['v' => null], 'v?: number | null'],
    'nullable, an empty string' => [fn ($s) => $s->integer()->nullable(), ['v' => ''], ['v' => null], 'v?: number | null'],
    'required and nullable, omitted' => [fn ($s) => $s->integer()->nullable()->required(), [], null, 'v: number | null'],
    'a string, omitted' => [fn ($s) => $s->string(), [], [], 'v?: string'],
    'a string, empty' => [fn ($s) => $s->string(), ['v' => ''], null, 'v?: string'],
    'a nullable string, empty' => [fn ($s) => $s->string()->nullable(), ['v' => ''], ['v' => null], 'v?: string | null'],
    'a required string, empty' => [fn ($s) => $s->string()->required(), ['v' => ''], null, 'v: string'],
    'a string enum, empty' => [fn ($s) => $s->string()->enum(['draft', 'published']), ['v' => ''], null, "v?: 'draft' | 'published'"],
    'a string of at least one character, empty' => [fn ($s) => $s->string()->min(1), ['v' => ''], null, 'v?: string'],
    'an email, empty' => [fn ($s) => $s->string()->format('email'), ['v' => ''], null, 'v?: string'],
    'a list of strings, an empty item' => [fn ($s) => $s->array()->items($s->string()), ['v' => ['a', '']], null, 'v?: Array<string>'],
    'a list of nullable strings, an empty item' => [fn ($s) => $s->array()->items($s->string()->nullable()), ['v' => ['a', '']], ['v' => ['a', null]], 'v?: Array<string | null>'],
    'an object\'s string key, empty' => [fn ($s) => $s->object(['title' => $s->string()]), ['v' => ['title' => '']], null, 'v?: { title?: string; }'],
    'a string, only spaces' => [fn ($s) => $s->string(), ['v' => " \t "], null, 'v?: string'],
    'a nullable string, only spaces' => [fn ($s) => $s->string()->nullable(), ['v' => '  '], ['v' => null], 'v?: string | null'],
    'an integer, only spaces' => [fn ($s) => $s->integer(), ['v' => '  '], null, 'v?: number'],
    'a number, empty' => [fn ($s) => $s->number(), ['v' => ''], null, 'v?: number'],
    'a boolean, empty' => [fn ($s) => $s->boolean(), ['v' => ''], null, 'v?: boolean'],
    'an integer enum, empty' => [fn ($s) => $s->integer()->enum([1, 2]), ['v' => ''], null, 'v?: 1 | 2'],
    'a list, empty' => [fn ($s) => $s->array()->items($s->integer()), ['v' => ''], null, 'v?: Array<number>'],
    'an object, empty' => [fn ($s) => $s->object(['author' => $s->string()->required()]), ['v' => ''], null, 'v?: { author: string; }'],
    'a string with more than spaces, kept as given' => [fn ($s) => $s->string(), ['v' => ' a '], ['v' => ' a '], 'v?: string'],
    'a list without items, an empty item' => [fn ($s) => $s->array(), ['v' => ['a', '']], ['v' => ['a', null]], 'v?: Array<Json>'],
    'an object without properties, a blank value' => [fn ($s) => $s->object(), ['v' => ['k' => ' ']], ['v' => ['k' => null]], 'v?: Record<string, Json>'],
    'a finite number' => [fn ($s) => $s->number()->required(), ['v' => 1.5e308], ['v' => 1.5e308], 'v: number'],
    'an infinite number' => [fn ($s) => $s->number()->required(), ['v' => INF], null, 'v: number'],
    'a negative infinite number' => [fn ($s) => $s->number()->required(), ['v' => -INF], null, 'v: number'],
    'not a number' => [fn ($s) => $s->number()->required(), ['v' => NAN], null, 'v: number'],
    'a number too large for a float' => [fn ($s) => $s->number()->required(), ['v' => '1e999'], null, 'v: number'],
    'a negative number too large for a float' => [fn ($s) => $s->number()->required(), ['v' => '-1e999'], null, 'v: number'],
    'nullable items, a null item' => [fn ($s) => $s->array()->items($s->string()->nullable()), ['v' => ['a', null]], ['v' => ['a', null]], 'v?: Array<string | null>'],
    'items, a null item' => [fn ($s) => $s->array()->items($s->string()), ['v' => [null]], null, 'v?: Array<string>'],
    'nullable object items, null among them' => [
        fn ($s) => $s->array()->items($s->object(['title' => $s->string()->required()])->nullable()),
        ['v' => [null, ['title' => 'A'], null]],
        ['v' => [null, ['title' => 'A'], null]],
        'v?: Array<{ title: string; } | null>',
    ],
    'object items, one with none of its keys' => [
        fn ($s) => $s->array()->items($s->object(['title' => $s->string()])),
        ['v' => [['extra' => 'x'], ['title' => 'A']]],
        ['v' => [[], ['title' => 'A']]],
        'v?: Array<{ title?: string; }>',
    ],
    'an optional object, empty' => [fn ($s) => $s->object(['title' => $s->string()->required()]), ['v' => []], [], 'v?: { title: string; }'],
]);

/**
 * One field, v, declared by the row; handle() hands back what it receives.
 */
final class TableField extends Action
{
    /**
     * @var (Closure(JsonSchema): Type)|null
     */
    public static ?Closure $field = null;

    protected ?Effect $effect = Effect::Read;

    public function schema(JsonSchema $schema): array
    {
        return ['v' => (self::$field)($schema)];
    }

    public function authorize(ActionContext $context): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(ValidatedInput $input): array
    {
        return $input->all();
    }
}
