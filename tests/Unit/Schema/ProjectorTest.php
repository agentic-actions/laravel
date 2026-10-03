<?php

use AgenticActions\Schema\Projector;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Illuminate\Support\Stringable;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/**
 * Project a result against an output schema written with the JSON Schema factory.
 *
 * @param  Closure(JsonSchemaTypeFactory): array<string, Type>  $schema
 * @return array<string, mixed>
 */
function project(mixed $result, Closure $schema): array
{
    return (new Projector)->project($result, (new ObjectType($schema(new JsonSchemaTypeFactory)))->toArray());
}

it('projects an empty schema to nothing', function () {
    expect(project(['id' => 1], fn ($s) => []))->toBe([])
        ->and(project(null, fn ($s) => []))->toBe([]);
});

it('drops undeclared keys at every depth', function () {
    $result = [
        'id' => 1,
        'secret' => 'x',
        'meta' => ['kept' => 'a', 'dropped' => 'b'],
        'items' => [['title' => 'A', 'token' => 't'], ['title' => 'B']],
    ];

    expect(project($result, fn ($s) => [
        'id' => $s->integer(),
        'meta' => $s->object(['kept' => $s->string()]),
        'items' => $s->array()->items($s->object(['title' => $s->string()])),
        'missing' => $s->string(),
    ]))->toBe([
        'id' => 1,
        'meta' => ['kept' => 'a'],
        'items' => [['title' => 'A'], ['title' => 'B']],
    ]);
});

it('reads a model through its declared keys only, never toArray()', function () {
    $note = ProjectedNote::query()->create(['title' => 'Hi', 'body' => 'hidden body', 'status' => 'draft', 'user_id' => Post::factory()->create()->user_id]);

    expect(project($note->fresh(), fn ($s) => ['id' => $s->integer(), 'title' => $s->string(), 'excerpt' => $s->string()->nullable()]))
        ->toBe(['id' => $note->getKey(), 'title' => 'Hi', 'excerpt' => null])
        ->and(project($note, fn ($s) => ['excerpt' => $s->string()->nullable()]))->toBe([])
        ->and(project($note, fn ($s) => ['shout' => $s->string(), 'user' => $s->object(['id' => $s->integer()])]))
        ->toBe(['shout' => 'HI', 'user' => ['id' => $note->user_id]]);
});

it('maps a Collection or any iterable as a list', function () {
    $posts = new Collection([['id' => 1, 'title' => 'A', 'body' => 'x'], ['id' => 2, 'title' => 'B', 'body' => 'y']]);
    $generator = (function () {
        yield 'first' => 'a';
        yield 'second' => 'b';
    })();

    expect(project(['posts' => $posts, 'tags' => $generator], fn ($s) => [
        'posts' => $s->array()->items($s->object(['id' => $s->integer(), 'title' => $s->string()])),
        'tags' => $s->array()->items($s->string()),
    ]))->toBe([
        'posts' => [['id' => 1, 'title' => 'A'], ['id' => 2, 'title' => 'B']],
        'tags' => ['a', 'b'],
    ]);
});

it('keeps only scalar items in a list declared without items', function () {
    expect(project(['tags' => ['a', 1, ['nested' => 'x'], new stdClass, null]], fn ($s) => ['tags' => $s->array()]))
        ->toBe(['tags' => ['a', 1, null]]);
});

it('formats dates as ISO-8601 and enums by value or name', function () {
    $date = CarbonImmutable::parse('2026-09-24 10:15:00', 'UTC');

    expect(project([
        'at' => $date,
        'status' => ProjectedStatus::Draft,
        'mood' => ProjectedMood::Calm,
        'label' => new Stringable('text'),
        'count' => 3,
    ], fn ($s) => [
        'at' => $s->string()->format('date-time'),
        'status' => $s->string(),
        'mood' => $s->string(),
        'label' => $s->string(),
        'count' => $s->integer(),
    ]))->toBe([
        'at' => '2026-09-24T10:15:00+00:00',
        'status' => 'draft',
        'mood' => 'Calm',
        'label' => 'text',
        'count' => 3,
    ]);
});

it('never stringifies a model where a string is declared', function () {
    $note = ProjectedNote::query()->create(['title' => 'Hi', 'body' => 'hidden body', 'status' => 'draft', 'user_id' => Post::factory()->create()->user_id]);

    expect(project(['note' => $note], fn ($s) => ['note' => $s->string()]))->toBe(['note' => null]);
});

it('omits a key whose value is missing and keeps an explicit null', function () {
    expect(project(['a' => null], fn ($s) => ['a' => $s->string()->nullable(), 'b' => $s->string()]))->toBe(['a' => null]);
});

it('projects an anyOf value through the branch its shape fits, whatever the order of the branches', function (array $branches, mixed $value, mixed $expected) {
    foreach ([$branches, array_reverse($branches)] as $order) {
        expect((new Projector)->project(['v' => $value], ['type' => 'object', 'properties' => ['v' => ['anyOf' => $order]]]))
            ->toBe(['v' => $expected]);
    }
})->with(function (): array {
    $object = ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]];
    $strings = ['type' => 'array', 'items' => ['type' => 'string']];
    $objects = ['type' => 'array', 'items' => $object];
    $string = ['type' => 'string'];

    return [
        'a string beside an object' => [[$object, $string], 'hello', 'hello'],
        'a number beside an object' => [[$object, ['type' => 'number']], 2.5, 2.5],
        'an array beside a string' => [[$object, $string], ['label' => 'A', 'secret' => 'x'], ['label' => 'A']],
        'an object beside a string' => [[$object, $string], (object) ['label' => 'A', 'secret' => 'x'], ['label' => 'A']],
        'a string beside a list' => [[$strings, $string], 'hello', 'hello'],
        'a list beside a string' => [[$strings, $string], ['a', 'b'], ['a', 'b']],
        'a list beside an object' => [[$object, $objects], [['label' => 'A', 'secret' => 'x']], [['label' => 'A']]],
        'a Collection beside an object' => [[$object, $objects], new Collection([['label' => 'A', 'secret' => 'x']]), [['label' => 'A']]],
        'an object beside a list of objects' => [[$object, $objects], ['label' => 'A', 'secret' => 'x'], ['label' => 'A']],
        'a string beside an object and a list only' => [[$object, $objects], 'secret', null],
        'an object inside a nested anyOf' => [[['anyOf' => [$object, ['type' => 'null']]], $string], ['label' => 'A', 'secret' => 'x'], ['label' => 'A']],
        'a string inside a nested anyOf' => [[['anyOf' => [$object, $string]], $objects], 'hello', 'hello'],
    ];
});

it('projects a value under a union of types through the type its shape fits, as a single type would', function (array $types, mixed $value, mixed $expected) {
    foreach ([$types, array_reverse($types)] as $order) {
        $node = ['type' => $order, 'properties' => ['label' => ['type' => 'string']], 'items' => ['type' => 'string']];

        expect((new Projector)->project(['v' => $value], ['type' => 'object', 'properties' => ['v' => $node]]))->toBe(['v' => $expected]);
    }
})->with([
    'a string for an object or a string' => [['object', 'string'], 'hello', 'hello'],
    'an object for an object or a string' => [['object', 'string'], ['label' => 'A', 'secret' => 'x'], ['label' => 'A']],
    'a number for a list or a number' => [['array', 'number'], 2.5, 2.5],
    'a list for a list or a number' => [['array', 'number'], ['a', 'b'], ['a', 'b']],
    'a list for an object or a list' => [['object', 'array'], ['a', 'b'], ['a', 'b']],
    'an object for an object or a list' => [['object', 'array'], ['label' => 'A', 'secret' => 'x'], ['label' => 'A']],
    'a string for an object or a list' => [['object', 'array'], 'secret', null],
]);

it('keeps a scalar under a union() of the JSON Schema factory', function () {
    expect(project(['v' => 'hello'], fn ($s) => ['v' => $s->union(['object', 'string'])]))->toBe(['v' => 'hello']);
});

enum ProjectedStatus: string
{
    case Draft = 'draft';
}

enum ProjectedMood
{
    case Calm;
}

/**
 * A post with a hidden attribute and an appended one.
 */
final class ProjectedNote extends Model
{
    protected $table = 'posts';

    protected $guarded = [];

    protected $hidden = ['body'];

    protected $appends = ['shout'];

    /**
     * The title, loudly.
     */
    public function getShoutAttribute(): string
    {
        return strtoupper((string) $this->title);
    }

    /**
     * The author.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
