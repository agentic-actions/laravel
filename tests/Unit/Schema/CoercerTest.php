<?php

use AgenticActions\Schema\Coercer;
use AgenticActions\Surface;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\UploadedFile;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\Type;

/**
 * Coerce one value declared with the JSON Schema factory, on a surface.
 *
 * @param  Closure(JsonSchemaTypeFactory): Type  $type
 */
function coerceOne(Closure $type, mixed $value, Surface $surface): mixed
{
    $node = (new ObjectType(['v' => $type(new JsonSchemaTypeFactory)]))->toArray();

    return (new Coercer)->coerce(['v' => $value], $node, $surface)['v'];
}

it('converts losslessly on every surface', function (Closure $type, mixed $value, mixed $expected) {
    foreach (Surface::cases() as $surface) {
        expect(coerceOne($type, $value, $surface))->toBe($expected);
    }
})->with([
    'integer' => [fn ($s) => $s->integer(), '12', 12],
    'negative integer' => [fn ($s) => $s->integer(), '-7', -7],
    'integer with leading zeros' => [fn ($s) => $s->integer(), '007', 7],
    'largest integer' => [fn ($s) => $s->integer(), (string) PHP_INT_MAX, PHP_INT_MAX],
    'smallest integer' => [fn ($s) => $s->integer(), (string) PHP_INT_MIN, PHP_INT_MIN],
    'integer out of range stays a string' => [fn ($s) => $s->integer(), '9223372036854775808', '9223372036854775808'],
    'decimal for an integer stays a string' => [fn ($s) => $s->integer(), '1.5', '1.5'],
    'integer with spaces stays a string' => [fn ($s) => $s->integer(), ' 1', ' 1'],
    'number, whole' => [fn ($s) => $s->number(), '3', 3],
    'number, decimal' => [fn ($s) => $s->number(), '2.5', 2.5],
    'number, exponent' => [fn ($s) => $s->number(), '1e3', 1000.0],
    'number with spaces stays a string' => [fn ($s) => $s->number(), '2.5 ', '2.5 '],
    'number too large stays a string' => [fn ($s) => $s->number(), '1e999', '1e999'],
    'number too small stays a string' => [fn ($s) => $s->number(), '-1e999', '-1e999'],
    'largest finite number' => [fn ($s) => $s->number(), '1.7976931348623157e308', PHP_FLOAT_MAX],
    'not a number stays a string' => [fn ($s) => $s->number(), 'abc', 'abc'],
    'true' => [fn ($s) => $s->boolean(), 'true', true],
    'false' => [fn ($s) => $s->boolean(), 'false', false],
    'a typed value is untouched' => [fn ($s) => $s->integer(), 5, 5],
    'a string stays a string' => [fn ($s) => $s->string(), '12', '12'],
]);

it('converts form-style values on Http and Console only', function (Closure $type, mixed $value, mixed $form) {
    foreach ([Surface::Http, Surface::Console] as $surface) {
        expect(coerceOne($type, $value, $surface))->toBe($form);
    }

    foreach ([Surface::Agent, Surface::Mcp, Surface::System, Surface::Queue] as $surface) {
        expect(coerceOne($type, $value, $surface))->toBe($value);
    }
})->with([
    '"1"' => [fn ($s) => $s->boolean(), '1', true],
    '"on"' => [fn ($s) => $s->boolean(), 'on', true],
    '"0"' => [fn ($s) => $s->boolean(), '0', false],
    '"off"' => [fn ($s) => $s->boolean(), 'off', false],
]);

it('never touches a file, and reads an empty string for a binary field as null', function () {
    $file = UploadedFile::fake()->create('a.txt');

    expect(coerceOne(fn ($s) => $s->string()->format('binary'), $file, Surface::Http))->toBe($file)
        ->and(coerceOne(fn ($s) => $s->string()->format('binary'), '', Surface::Http))->toBeNull()
        ->and(coerceOne(fn ($s) => $s->string()->format('binary')->nullable(), '', Surface::Http))->toBeNull();
});

it('reads an empty string, or one of only whitespace, as null for every field, on every surface', function (Closure $type) {
    foreach (Surface::cases() as $surface) {
        foreach (['', ' ', " \t\r\n", "\u{00A0}\u{200B}\u{200F}"] as $blank) {
            expect(coerceOne($type, $blank, $surface))->toBeNull();
        }
    }
})->with([
    'integer' => [fn ($s) => $s->integer()],
    'number' => [fn ($s) => $s->number()],
    'boolean' => [fn ($s) => $s->boolean()],
    'enum of integers' => [fn ($s) => $s->integer()->enum([1, 2])],
    'list' => [fn ($s) => $s->array()],
    'object' => [fn ($s) => $s->object()],
    'nullable integer' => [fn ($s) => $s->integer()->nullable()],
    'string' => [fn ($s) => $s->string()],
    'nullable string' => [fn ($s) => $s->string()->nullable()],
    'string enum that lists an empty string' => [fn ($s) => $s->string()->enum(['', 'a'])],
    'string of at least one character' => [fn ($s) => $s->string()->min(1)],
    'email' => [fn ($s) => $s->string()->format('email')],
    'union with string' => [fn ($s) => $s->union(['string', 'integer'])],
]);

it('never trims a string that holds more than whitespace, on every surface', function (string $value) {
    foreach (Surface::cases() as $surface) {
        expect(coerceOne(fn ($s) => $s->string(), $value, $surface))->toBe($value);
    }
})->with([
    'spaces around a word' => [' a '],
    'a mark before a word' => ["\u{200F}a"],
]);

it('tells a long string with spaces inside from a blank one in time linear in its length', function () {
    $value = 'a'.str_repeat(' ', 100_000).'b';
    $started = hrtime(true);

    expect(coerceOne(fn ($s) => $s->string(), $value, Surface::Agent))->toBe($value)
        ->and(hrtime(true) - $started)->toBeLessThan(1_000_000_000);
});

it('reads an empty or blank string as null at every depth, for keys the schema does not declare too, as the web does', function () {
    $s = new JsonSchemaTypeFactory;
    $node = (new ObjectType([
        'meta' => $s->object(['label' => $s->string()]),
        'tags' => $s->array()->items($s->string()),
        'rows' => $s->array()->items($s->object(['title' => $s->string()->nullable()])),
        'labels' => $s->array(),
        'extra' => $s->object(),
        'topic' => $s->union(['string', 'array']),
    ]))->toArray();

    foreach (Surface::cases() as $surface) {
        expect((new Coercer)->coerce([
            'meta' => ['label' => '', 'other' => ''],
            'tags' => ['a', '', ' '],
            'rows' => [['title' => ''], ['title' => 'B', 'other' => ' ']],
            'labels' => ['a', '', "\t"],
            'extra' => ['k' => '', 'n' => ['m' => '  ', 'kept' => ' x ']],
            'topic' => ['a', ''],
            'undeclared' => '',
            'slug' => ['x' => ' '],
        ], $node, $surface))->toBe([
            'meta' => ['label' => null, 'other' => null],
            'tags' => ['a', null, null],
            'rows' => [['title' => null], ['title' => 'B', 'other' => null]],
            'labels' => ['a', null, null],
            'extra' => ['k' => null, 'n' => ['m' => null, 'kept' => ' x ']],
            'topic' => ['a', null],
            'undeclared' => null,
            'slug' => ['x' => null],
        ]);
    }
});

it('keeps a blank string for the keys TrimStrings leaves alone, at the top level only, and reads their empty string as null', function () {
    $s = new JsonSchemaTypeFactory;
    $node = (new ObjectType([
        'password' => $s->string(),
        'account' => $s->object(['password' => $s->string()]),
    ]))->toArray();

    foreach (Surface::cases() as $surface) {
        expect((new Coercer)->coerce([
            'password' => '   ',
            'password_confirmation' => "\t",
            'current_password' => "\u{00A0}",
            'account' => ['password' => '  '],
            'items' => [['password_confirmation' => ' ']],
        ], $node, $surface))->toBe([
            'password' => '   ',
            'password_confirmation' => "\t",
            'current_password' => "\u{00A0}",
            'account' => ['password' => null],
            'items' => [['password_confirmation' => null]],
        ])->and((new Coercer)->coerce(['password' => '', 'password_confirmation' => ''], $node, $surface))
            ->toBe(['password' => null, 'password_confirmation' => null]);
    }
});

it('keeps TrimStrings\' default list of keys it leaves alone', function () {
    expect((new ReflectionClassConstant(Coercer::class, 'UNTRIMMED'))->getValue())
        ->toBe((new ReflectionProperty(TrimStrings::class, 'except'))->getDefaultValue());
});

it('coerces nested objects and list items, and leaves undeclared keys untouched', function () {
    $s = new JsonSchemaTypeFactory;
    $node = (new ObjectType([
        'meta' => $s->object(['count' => $s->integer(), 'flag' => $s->boolean()]),
        'items' => $s->array()->items($s->object(['size' => $s->number()])),
        'ids' => $s->array()->items($s->integer()),
    ]))->toArray();

    expect((new Coercer)->coerce([
        'meta' => ['count' => '3', 'flag' => 'on', 'other' => '4'],
        'items' => [['size' => '1.5', 'extra' => '2'], ['size' => 'big']],
        'ids' => ['1', '2', 'x'],
        'undeclared' => '5',
    ], $node, Surface::Http))->toBe([
        'meta' => ['count' => 3, 'flag' => true, 'other' => '4'],
        'items' => [['size' => 1.5, 'extra' => '2'], ['size' => 'big']],
        'ids' => [1, 2, 'x'],
        'undeclared' => '5',
    ]);
});

it('leaves a value whose shape does not match its node alone', function () {
    $s = new JsonSchemaTypeFactory;
    $node = (new ObjectType(['meta' => $s->object(['count' => $s->integer()]), 'count' => $s->integer()]))->toArray();

    expect((new Coercer)->coerce(['meta' => 'text', 'count' => ['1']], $node, Surface::Http))
        ->toBe(['meta' => 'text', 'count' => ['1']]);
});
