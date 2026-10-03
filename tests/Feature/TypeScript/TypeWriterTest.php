<?php

use AgenticActions\TypeScript\TypeWriter;

it('maps each schema node to its TypeScript type', function (array $node, string $type) {
    expect((new TypeWriter)->type($node))->toBe($type);
})->with([
    'string' => [['type' => 'string'], 'string'],
    'string enum' => [['type' => 'string', 'enum' => ['draft', 'published']], "'draft' | 'published'"],
    'binary' => [['type' => 'string', 'format' => 'binary'], 'File | Blob'],
    'integer' => [['type' => 'integer'], 'number'],
    'number' => [['type' => 'number'], 'number'],
    'number enum' => [['type' => 'number', 'enum' => [1, 2.5]], '1 | 2.5'],
    'boolean' => [['type' => 'boolean'], 'boolean'],
    'array of strings' => [['type' => 'array', 'items' => ['type' => 'string']], 'Array<string>'],
    'array of a union' => [['type' => 'array', 'items' => ['type' => ['string', 'null']]], 'Array<string | null>'],
    'array of a literal holding a bar' => [['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['a|b']]], "Array<'a|b'>"],
    'array without items' => [['type' => 'array'], 'Array<Json>'],
    'object without properties' => [['type' => 'object'], 'Record<string, Json>'],
    'nullable' => [['type' => ['integer', 'null']], 'number | null'],
    'nullable enum' => [['type' => ['string', 'null'], 'enum' => ['a', null]], "'a' | null"],
    'nullable binary' => [['type' => ['string', 'null'], 'format' => 'binary'], 'File | Blob | null'],
    'a union of types' => [['type' => ['string', 'integer']], 'string | number'],
    'anyOf' => [['anyOf' => [['type' => 'string'], ['type' => 'boolean']]], 'string | boolean'],
    'no type' => [[], 'Json'],
    'an unknown type' => [['type' => 'date'], 'Json'],
    'only null' => [['type' => 'null'], 'null'],
    'an enum literal with quotes' => [['type' => 'string', 'enum' => ["it's", 'back\\slash']], "'it\\'s' | 'back\\\\slash'"],
]);

it('writes an object one property per line, minus the given keys', function () {
    $object = [
        'type' => 'object',
        'properties' => [
            'post' => ['type' => 'integer'],
            'title' => ['type' => 'string', 'description' => "Shown\n  in lists."],
            '2fa' => ['type' => 'boolean', 'default' => false],
            'tags' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
                'required' => ['name'],
            ]],
        ],
        'required' => ['post', 'title', '2fa'],
    ];

    expect((new TypeWriter)->object($object, ['post'], 1))->toBe(<<<'TS'
        {
                /** Shown in lists. */
                title: string;
                '2fa': boolean;
                tags?: Array<{
                    name: string;
                }>;
            }
        TS)
        ->and((new TypeWriter)->object($object, ['post', 'title', '2fa', 'tags']))->toBe('Record<string, never>');
});

it('makes a key optional only when it is not required, whatever its default', function () {
    $object = [
        'type' => 'object',
        'properties' => [
            'required_default' => ['type' => 'integer', 'default' => 1],
            'required' => ['type' => 'integer'],
            'optional_default' => ['type' => 'integer', 'default' => 1],
            'nullable_default' => ['type' => ['integer', 'null'], 'default' => null],
        ],
        'required' => ['required_default', 'required'],
    ];

    expect((new TypeWriter)->object($object))->toBe(<<<'TS'
        {
            required_default: number;
            required: number;
            optional_default?: number;
            nullable_default?: number | null;
        }
        TS);
});
