<?php

use AgenticActions\Exceptions\UnsupportedSchema;
use AgenticActions\NamedRule;
use AgenticActions\Schema\RuleCompiler;
use AgenticActions\Surface;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\In;

/**
 * Compile the rules for a schema written with the JSON Schema factory.
 *
 * @param  Closure(JsonSchemaTypeFactory): array<string, Type>  $schema
 * @param  list<string>  $owned
 * @return array<string, list<mixed>>
 */
function compileSchema(Closure $schema, Surface $surface = Surface::Http, array $owned = []): array
{
    $node = (new ObjectType($schema(new JsonSchemaTypeFactory)))->toArray();

    return (new RuleCompiler)->compile($node, $surface, $owned);
}

it('compiles presence for top-level keys', function () {
    expect(compileSchema(fn ($s) => [
        'required' => $s->string()->required(),
        'nullable' => $s->string()->required()->nullable(),
        'optional' => $s->string(),
        'optional_nullable' => $s->string()->nullable(),
    ]))->toBe([
        'required' => ['required', 'string'],
        'nullable' => ['present', 'nullable', 'string'],
        'optional' => ['sometimes', 'string'],
        'optional_nullable' => ['sometimes', 'nullable', 'string'],
    ]);
});

it('compiles string constraints and formats', function (Closure $type, array $rules) {
    expect(compileSchema(fn ($s) => ['v' => $type($s)])['v'])->toBe(['sometimes', ...$rules]);
})->with([
    'min and max length' => [fn ($s) => $s->string()->min(2)->max(9), ['string', 'min:2', 'max:9']],
    'pattern' => [fn ($s) => $s->string()->pattern('^[a-z]+/[0-9]+$'), ['string', 'regex:/^[a-z]+\/[0-9]+$/uD']],
    'pattern with an escaped slash' => [fn ($s) => $s->string()->pattern('^a\/b$'), ['string', 'regex:/^a\/b$/uD']],
    'email' => [fn ($s) => $s->string()->format('email'), ['string', 'email']],
    'uri' => [fn ($s) => $s->string()->format('uri'), ['string', 'url']],
    'url' => [fn ($s) => $s->string()->format('url'), ['string', 'url']],
    'uuid' => [fn ($s) => $s->string()->format('uuid'), ['string', 'uuid']],
    'date' => [fn ($s) => $s->string()->format('date'), ['string', 'date_format:Y-m-d']],
    'date-time' => [fn ($s) => $s->string()->format('date-time'), ['string', 'date']],
    'time' => [fn ($s) => $s->string()->format('time'), ['string', 'date_format:H:i:s']],
    'ipv4' => [fn ($s) => $s->string()->format('ipv4'), ['string', 'ipv4']],
    'ipv6' => [fn ($s) => $s->string()->format('ipv6'), ['string', 'ipv6']],
    'binary' => [fn ($s) => $s->string()->format('binary'), ['file']],
]);

it('compiles numbers, booleans and lists', function (Closure $type, array $rules) {
    expect(compileSchema(fn ($s) => ['v' => $type($s)])['v'])->toBe(['sometimes', ...$rules]);
})->with([
    'integer' => [fn ($s) => $s->integer(), ['integer:strict']],
    'integer bounds' => [fn ($s) => $s->integer()->min(1)->max(10), ['integer:strict', 'bail', 'min:1', 'max:10']],
    'boolean' => [fn ($s) => $s->boolean(), ['boolean:strict']],
    'list' => [fn ($s) => $s->array(), ['list']],
    'list size' => [fn ($s) => $s->array()->min(1)->max(3), ['list', 'min:1', 'max:3']],
]);

it('compiles a number to a strict numeric rule, a finite check named numeric, then bail and its bounds', function () {
    $rules = compileSchema(fn ($s) => ['v' => $s->number()->min(0)->max(5)])['v'];

    expect($rules)->toHaveCount(6)
        ->and([$rules[0], $rules[1], $rules[3], $rules[4], $rules[5]])->toBe(['sometimes', 'numeric:strict', 'bail', 'min:0', 'max:5'])
        ->and($rules[2])->toBeInstanceOf(NamedRule::class)
        ->and($rules[2]->name)->toBe('numeric');
});

it('refuses a number that is not finite, bounded or not, in a list too', function (Closure $type, mixed $value, ?string $message) {
    $rules = compileSchema(fn ($s) => ['v' => $type($s), 'list' => $s->array()->items($type($s))]);

    // The one message: a bound would add its own after the type's.
    expect(Validator::make(['v' => $value], $rules)->errors()->get('v'))->toBe($message === null ? [] : [$message])
        ->and(Validator::make(['list' => [1, $value]], $rules)->passes())->toBe($message === null);
})->with([
    'infinity' => [fn ($s) => $s->number(), INF, 'The v field must be a number.'],
    'negative infinity' => [fn ($s) => $s->number(), -INF, 'The v field must be a number.'],
    'not a number' => [fn ($s) => $s->number(), NAN, 'The v field must be a number.'],
    'infinity, bounded' => [fn ($s) => $s->number()->min(0)->max(9), INF, 'The v field must be a number.'],
    'infinity for an integer' => [fn ($s) => $s->integer(), INF, 'The v field must be an integer.'],
    'not a number, bounded' => [fn ($s) => $s->number()->min(0)->max(9)->multipleOf(0.5), NAN, 'The v field must be a number.'],
    'infinity for an integer, bounded' => [fn ($s) => $s->integer()->min(0)->max(9), INF, 'The v field must be an integer.'],
    'a decimal for an integer, below its bound' => [fn ($s) => $s->integer()->min(0), -2.5, 'The v field must be an integer.'],
    'the largest finite number' => [fn ($s) => $s->number(), PHP_FLOAT_MAX, null],
    'a decimal' => [fn ($s) => $s->number(), 2.5, null],
    'an integer' => [fn ($s) => $s->number(), 3, null],
]);

it('ends a pattern at the end of the value, as the JSON Schema it is advertised as does', function (string $value, bool $passes) {
    $rules = compileSchema(fn ($s) => ['v' => $s->string()->pattern('^[a-z0-9_]+$')->required()]);

    expect(Validator::make(['v' => $value], $rules)->passes())->toBe($passes);
})->with([
    'a slug' => ['admin', true],
    'a slug and a trailing newline' => ["admin\n", false],
    'a slug and a carriage return' => ["admin\r", false],
]);

it('compiles multipleOf', function () {
    $node = ['type' => 'object', 'properties' => ['v' => ['type' => 'integer', 'multipleOf' => 5]]];

    expect((new RuleCompiler)->compile($node, Surface::Http))->toBe(['v' => ['sometimes', 'integer:strict', 'bail', 'multiple_of:5']]);
});

it('compiles list items at "{path}.*" and unique items as distinct', function () {
    expect(compileSchema(fn ($s) => [
        'tags' => $s->array()->items($s->string()->max(10))->unique()->required(),
    ]))->toBe([
        'tags' => ['required', 'list'],
        'tags.*' => ['string', 'max:10', 'distinct:strict'],
    ]);
});

it('compiles required_with for nested objects and required inside list items', function () {
    expect(compileSchema(fn ($s) => [
        'meta' => $s->object([
            'author' => $s->string()->required(),
            'note' => $s->string(),
            'deep' => $s->object(['level' => $s->integer()->required()]),
        ]),
        'items' => $s->array()->items($s->object([
            'title' => $s->string()->required(),
            'size' => $s->integer(),
        ])),
    ]))->toBe([
        'meta' => ['sometimes', 'array'],
        'meta.author' => ['required_with:meta', 'string'],
        'meta.note' => ['sometimes', 'string'],
        'meta.deep' => ['sometimes', 'array'],
        'meta.deep.level' => ['required_with:meta.deep', 'integer:strict'],
        'items' => ['sometimes', 'list'],
        'items.*' => ['array'],
        'items.*.title' => ['required', 'string'],
        'items.*.size' => ['sometimes', 'integer:strict'],
    ]);
});

it('accepts a null list item where the items are nullable, and checks an object item\'s children only when it is not null', function (array $input, array $errors) {
    $validator = Validator::make($input, compileSchema(fn ($s) => [
        'tags' => $s->array()->items($s->string()->nullable()),
        'names' => $s->array()->items($s->string()),
        'rows' => $s->array()->items($s->object([
            'title' => $s->string()->required(),
            'note' => $s->string()->required()->nullable(),
            'size' => $s->integer(),
        ])->nullable()),
    ]));

    expect($validator->errors()->keys())->toBe($errors);

    if ($errors === []) {
        // Laravel's validated() may add a list's null items before its object items; the Runner puts them back in order.
        expect($validator->validated())->toEqual($input);
    }
})->with([
    'null string items' => [['tags' => [null, 'a', null]], []],
    'a null item where items are not nullable' => [['names' => [null]], ['names.0']],
    'a null object item' => [['rows' => [null]], []],
    'null and complete object items' => [['rows' => [null, ['title' => 'A', 'note' => null], null]], []],
    'an object item missing its required children' => [['rows' => [null, ['size' => 1]]], ['rows.1.title', 'rows.1.note']],
    'an empty object item' => [['rows' => [[]]], ['rows.0.title', 'rows.0.note']],
    'an item of the wrong type' => [['tags' => [1], 'rows' => ['text']], ['tags.0', 'rows.0', 'rows.0.title', 'rows.0.note']],
]);

it('closes an object declared without additional properties to its keys', function () {
    expect(compileSchema(fn ($s) => [
        'meta' => $s->object(['a' => $s->string(), 'b' => $s->string()])->withoutAdditionalProperties()->required(),
    ])['meta'])->toBe(['required', 'array:a,b']);
});

it('compiles an enum to an in rule', function () {
    $rules = compileSchema(fn ($s) => ['status' => $s->string()->enum(['draft', 'published'])->required()])['status'];

    expect($rules[0])->toBe('required')
        ->and($rules[1])->toBe('string')
        ->and($rules[2])->toBeInstanceOf(In::class)
        ->and((string) $rules[2])->toBe('in:"draft","published"');
});

it('advertises defaults, descriptions and titles without rules', function () {
    expect(compileSchema(fn ($s) => [
        'title' => $s->string()->default('Untitled')->description('The title.')->title('Title'),
    ]))->toBe(['title' => ['sometimes', 'string']]);
});

it('refuses shapes it does not support', function (array $property, string $reason) {
    $node = ['type' => 'object', 'properties' => ['v' => $property]];

    expect(fn () => (new RuleCompiler)->compile($node, Surface::Http))
        ->toThrow(UnsupportedSchema::class, "[v] {$reason}");
})->with([
    'a union' => [['type' => ['string', 'integer']], 'a union of string and integer is not supported'],
    'anyOf' => [['anyOf' => [['type' => 'string'], ['type' => 'integer']]], 'anyOf is not supported'],
    'a lookahead' => [['type' => 'string', 'pattern' => '^(?=a)'], 'pattern lookaround (?= is not supported'],
    'a negative lookahead' => [['type' => 'string', 'pattern' => '^(?!a)'], 'pattern lookaround (?! is not supported'],
    'a lookbehind' => [['type' => 'string', 'pattern' => '(?<=a)b'], 'pattern lookaround (?<= is not supported'],
    'a negative lookbehind' => [['type' => 'string', 'pattern' => '(?<!a)b'], 'pattern lookaround (?<! is not supported'],
    'an unknown format' => [['type' => 'string', 'format' => 'hostname'], 'format hostname is not supported'],
]);

it('refuses a nested union too', function () {
    $node = ['type' => 'object', 'properties' => ['meta' => ['type' => 'object', 'properties' => ['v' => ['type' => ['string', 'number']]]]]];

    expect(fn () => (new RuleCompiler)->compile($node, Surface::Http, ['meta']))->toThrow(UnsupportedSchema::class, '[meta.v]');
});

it('lets rules() own a top-level union key, emitting only its presence', function () {
    $node = ['type' => 'object', 'required' => ['v'], 'properties' => [
        'v' => ['type' => ['string', 'integer']],
        'w' => ['anyOf' => [['type' => 'string'], ['type' => 'integer']]],
    ]];

    expect((new RuleCompiler)->compile($node, Surface::Http, ['v', 'w']))->toBe([
        'v' => ['required'],
        'w' => ['sometimes'],
    ]);
});

it('accepts a binary field on HTTP only', function (Surface $surface) {
    expect(fn () => compileSchema(fn ($s) => ['file' => $s->string()->format('binary')], $surface))
        ->toThrow(UnsupportedSchema::class, '[file] format binary (a file) is accepted on HTTP only');
})->with([Surface::Agent, Surface::Console, Surface::System]);

it('names the class once one is given', function () {
    expect(UnsupportedSchema::at('v', 'anyOf is not supported')->withClass('App\\Actions\\Thing')->getMessage())
        ->toBe('App\\Actions\\Thing: [v] anyOf is not supported');
});

it('compiles an empty schema to no rules', function () {
    expect((new RuleCompiler)->compile(['type' => 'object'], Surface::Http))->toBe([]);
});
