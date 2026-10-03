<?php

use AgenticActions\NamedRule;
use Illuminate\Support\Facades\Validator;

it('records every named rule that fails on one key', function () {
    $validator = Validator::make(['name' => 'x'], [
        'name' => [
            new NamedRule('person_name', fn (mixed $value): bool => false),
            new NamedRule('not_reserved', fn (mixed $value): bool => false),
            new NamedRule('passes', fn (mixed $value): bool => true),
        ],
    ]);

    expect($validator->fails())->toBeTrue()
        ->and(NamedRule::failuresFor($validator))->toBe(['name' => ['person_name', 'not_reserved']])
        ->and($validator->errors()->get('name'))->toBe(['The name field is invalid.']);
});

it('keeps each validator\'s failures apart', function () {
    $rule = fn (): array => ['name' => [new NamedRule('person_name', fn (mixed $value): bool => $value === 'ok')]];

    $failing = Validator::make(['name' => 'x'], $rule());
    $passing = Validator::make(['name' => 'ok'], $rule());

    $failing->fails();
    $passing->fails();

    expect(NamedRule::failuresFor($failing))->toBe(['name' => ['person_name']])
        ->and(NamedRule::failuresFor($passing))->toBe([]);
});

it('records list items by their index path', function () {
    $validator = Validator::make(['items' => ['a', 'b']], [
        'items.*' => [new NamedRule('two_letters', fn (mixed $value): bool => $value === 'b')],
    ]);

    $validator->fails();

    expect(NamedRule::failuresFor($validator))->toBe(['items.0' => ['two_letters']]);
});

it('uses its own message when given one', function () {
    $validator = Validator::make(['name' => 'x'], [
        'name' => [new NamedRule('person_name', fn (mixed $value): bool => false, 'Give a first and a last name for :attribute.')],
    ]);

    expect($validator->errors()->first('name'))->toBe('Give a first and a last name for name.');
});

it('passes the web message through the translator', function () {
    $directory = sys_get_temp_dir().'/agentic-actions-lang-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents("{$directory}/ar.json", json_encode(['The :attribute field is invalid.' => 'حقل :attribute غير صالح.']));
    app('translator')->addJsonPath($directory);
    app()->setLocale('ar');

    $validator = Validator::make(['name' => 'x'], ['name' => [new NamedRule('person_name', fn (mixed $value): bool => false)]]);

    expect($validator->errors()->first('name'))->toBe('حقل name غير صالح.');
});
