<?php

use AgenticActions\Ask;
use AgenticActions\Elicitation\FormSchema;
use Illuminate\Validation\Rules\Password;

/*
 * FormSchema: one advertised field in MCP's restricted subset, or null when a form cannot hold it. The
 * property is golden: what a form sends is exactly this, and nothing a form does not name (no pattern, no multipleOf,
 * no uniqueItems) ever crosses.
 */

/**
 * The property for a node, with an Ask built by the callback.
 *
 * @param  array<string, mixed>  $node
 * @param  list<mixed>  $candidates
 * @param  list<mixed>  $rules
 * @return array{0: array<string, mixed>, 1: array<string, int|float|string>}|null
 */
function seams05Property(string $key, array $node, ?Closure $ask = null, array $candidates = [], array $rules = []): ?array
{
    return FormSchema::property($key, $node, ($ask ?? fn (Ask $ask): Ask => $ask)(new Ask)->toArray(), $candidates, $rules);
}

describe('text', function () {
    it('keeps the formats the standard names, sending url as uri, and drops the rest', function (string $format, ?string $sent) {
        expect(seams05Property('link', ['type' => 'string', 'format' => $format])[0])
            ->toBe(['type' => 'string', 'title' => 'Link', ...($sent === null ? [] : ['format' => $sent])]);
    })->with([
        'email' => ['email', 'email'],
        'uri' => ['uri', 'uri'],
        'url' => ['url', 'uri'],
        'date' => ['date', 'date'],
        'date-time' => ['date-time', 'date-time'],
        'uuid' => ['uuid', null],
        'time' => ['time', null],
    ]);

    it('sends the title, the description and the lengths, and never a pattern', function () {
        expect(seams05Property('title', ['type' => 'string', 'title' => 'Title', 'description' => "The post's\ntitle.", 'minLength' => 1, 'maxLength' => 120, 'pattern' => '^[A-Z]'])[0])
            ->toBe(['type' => 'string', 'title' => 'Title', 'description' => "The post's title.", 'minLength' => 1, 'maxLength' => 120]);
    });

    it('falls back from the node\'s title to the key as a headline', function () {
        expect(seams05Property('first_name', ['type' => 'string'])[0]['title'])->toBe('First Name')
            ->and(seams05Property('first_name', ['type' => 'string', 'title' => 'Given name'])[0]['title'])->toBe('Given name');
    });

    it('reads a nullable type as its other type', function () {
        expect(seams05Property('excerpt', ['type' => ['string', 'null'], 'maxLength' => 200])[0])
            ->toBe(['type' => 'string', 'title' => 'Excerpt', 'maxLength' => 200]);
    });

    it('adds the textarea hint on a text field, and throws for one on any other field', function () {
        expect(seams05Property('body', ['type' => 'string'], fn (Ask $ask): Ask => $ask->textarea('body'))[0])
            ->toBe(['type' => 'string', 'title' => 'Body', 'x-agentic-actions' => ['widget' => 'textarea']])
            ->and(fn () => seams05Property('count', ['type' => 'integer'], fn (Ask $ask): Ask => $ask->textarea('count')))
            ->toThrow(LogicException::class, 'ask() shows [count] as a textarea, which only a text field can be.');
    });
});

describe('numbers and yes or no', function () {
    it('sends the bounds and never multipleOf', function (string $type) {
        expect(seams05Property('count', ['type' => $type, 'minimum' => 1, 'maximum' => 10, 'multipleOf' => 2]))
            ->toBe([['type' => $type, 'title' => 'Count', 'minimum' => 1, 'maximum' => 10], []]);
    })->with(['integer', 'number']);

    it('sends a boolean', function () {
        expect(seams05Property('share', ['type' => 'boolean', 'description' => 'Share it.'], candidates: [false]))
            ->toBe([['type' => 'boolean', 'title' => 'Share', 'description' => 'Share it.', 'default' => false], []]);
    });
});

describe('choices', function () {
    it('sends a string enum untitled', function () {
        expect(seams05Property('status', ['type' => 'string', 'enum' => ['draft', 'published']]))
            ->toBe([['type' => 'string', 'title' => 'Status', 'enum' => ['draft', 'published']], []]);
    });

    it('sends choices() as titled choices in their order, each in the enum', function () {
        $choices = fn (Ask $ask): Ask => $ask->choices('status', ['published' => 'Published', 'draft' => 'Draft']);

        expect(seams05Property('status', ['type' => 'string', 'enum' => ['draft', 'published']], $choices))
            ->toBe([['type' => 'string', 'title' => 'Status', 'oneOf' => [['const' => 'published', 'title' => 'Published'], ['const' => 'draft', 'title' => 'Draft']]], []])
            ->and(fn () => seams05Property('status', ['type' => 'string', 'enum' => ['draft']], $choices))
            ->toThrow(LogicException::class, 'ask() gives [status] the choice ["published"], which the field does not accept.');
    });

    it('closes a string without an enum to its choices', function () {
        expect(seams05Property('assignee', ['type' => 'string'], fn (Ask $ask): Ask => $ask->choices('assignee', ['Ada Lovelace' => 'Ada Lovelace'])))
            ->toBe([['type' => 'string', 'title' => 'Assignee', 'oneOf' => [['const' => 'Ada Lovelace', 'title' => 'Ada Lovelace']]], []]);
    });

    it('sends a numeric enum as strings titled with themselves, mapped back to numbers', function () {
        expect(seams05Property('size', ['type' => 'integer', 'enum' => [12, 24]], candidates: [24]))
            ->toBe([['type' => 'string', 'title' => 'Size', 'oneOf' => [['const' => '12', 'title' => '12'], ['const' => '24', 'title' => '24']], 'default' => '24'], ['12' => 12, '24' => 24]])
            ->and(seams05Property('rate', ['type' => 'number', 'enum' => [1.5, 2]])[1])->toBe(['1.5' => 1.5, '2' => 2]);
    });

    it('sends a const-mapped default as its const string, never a number', function () {
        [$property, $map] = seams05Property('owner', ['type' => 'integer'], fn (Ask $ask): Ask => $ask->choices('owner', [5 => 'Ada', 9 => 'Grace'])->default('owner', 5));

        expect($property)->toBe(['type' => 'string', 'title' => 'Owner', 'oneOf' => [['const' => '5', 'title' => 'Ada'], ['const' => '9', 'title' => 'Grace']]])
            ->and($map)->toBe(['5' => 5, '9' => 9]);

        [$property] = seams05Property('owner', ['type' => 'integer'], fn (Ask $ask): Ask => $ask->choices('owner', [5 => 'Ada', 9 => 'Grace'])->default('owner', 5), [5]);

        expect($property['default'])->toBe('5')
            ->and(json_encode($property))->toContain('"default":"5"');
    });

    it('refuses a choice the field\'s type does not accept', function () {
        expect(fn () => seams05Property('owner', ['type' => 'integer'], fn (Ask $ask): Ask => $ask->choices('owner', ['ada' => 'Ada'])))
            ->toThrow(LogicException::class, 'ask() gives [owner] the choice ["ada"], which the field does not accept.');
    });
});

describe('lists', function () {
    it('sends a multi-select untitled, with its bounds, and never uniqueItems', function () {
        expect(seams05Property('tags', ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['news', 'ops']], 'minItems' => 1, 'maxItems' => 2, 'uniqueItems' => true], candidates: [['ops']]))
            ->toBe([['type' => 'array', 'title' => 'Tags', 'items' => ['type' => 'string', 'enum' => ['news', 'ops']], 'minItems' => 1, 'maxItems' => 2, 'default' => ['ops']], []]);
    });

    it('sends a multi-select titled by choices()', function () {
        expect(seams05Property('tags', ['type' => 'array', 'items' => ['type' => 'string']], fn (Ask $ask): Ask => $ask->choices('tags', ['news' => 'News', 'ops' => 'Operations'])))
            ->toBe([['type' => 'array', 'title' => 'Tags', 'items' => ['anyOf' => [['const' => 'news', 'title' => 'News'], ['const' => 'ops', 'title' => 'Operations']]]], []]);
    });

    it('sends a numeric multi-select as const strings, its default a list of strings', function () {
        [$property, $map] = seams05Property('ids', ['type' => 'array', 'items' => ['type' => 'integer']], fn (Ask $ask): Ask => $ask->choices('ids', [1 => 'One', 2 => 'Two']), [[2, 1]]);

        expect($property)->toBe(['type' => 'array', 'title' => 'Ids', 'items' => ['anyOf' => [['const' => '1', 'title' => 'One'], ['const' => '2', 'title' => 'Two']]], 'default' => ['2', '1']])
            ->and($map)->toBe(['1' => 1, '2' => 2]);
    });
});

describe('what a form cannot hold', function () {
    it('gives null', function (array $node) {
        expect(seams05Property('field', $node))->toBeNull();
    })->with([
        'an object' => [['type' => 'object', 'properties' => ['street' => ['type' => 'string']]]],
        'a list of objects' => [['type' => 'array', 'items' => ['type' => 'object', 'properties' => []]]],
        'a free-text list' => [['type' => 'array', 'items' => ['type' => 'string']]],
        'a list of booleans' => [['type' => 'array', 'items' => ['type' => 'boolean']]],
        'anyOf' => [['anyOf' => [['type' => 'string'], ['type' => 'integer']]]],
        'a union' => [['type' => ['string', 'integer']]],
        'binary' => [['type' => 'string', 'format' => 'binary']],
        'no type' => [[]],
    ]);
});

describe('secrets', function () {
    beforeEach(fn () => config(['agentic-actions.agents.forbidden_keys' => []]));

    it('never asks a field whose key reads as a secret', function (string $key) {
        expect(seams05Property($key, ['type' => 'string']))->toBeNull();
    })->with(['password', 'passwd', 'pwd', 'pin', 'pin_code', 'pinCode', 'api-key', 'apiKey', 'APIKey', 'access_key', 'auth_code',
        'verification_code', 'mfa_code', 'totp', 'recovery_code', 'security_code', 'cvv2', 'card_cvv2', 'cc_number', 'credit_card',
        'account_number', 'routing_number', 'sort_code', 'iban', 'otp', '2fa', 'client_secret', 'refresh_token']);

    it('asks a field whose key only contains such letters', function (string $key) {
        expect(seams05Property($key, ['type' => 'string']))->not->toBeNull();
    })->with(['shipping', 'option', 'opinion', 'pinned', 'spinner', 'passage', 'tokenizer_mode']);

    it('never asks an innocuous key titled or described as a secret', function () {
        expect(seams05Property('code', ['type' => 'string', 'title' => 'PIN']))->toBeNull()
            ->and(seams05Property('answer', ['type' => 'string', 'description' => 'Your password']))->toBeNull()
            ->and(seams05Property('answer', ['type' => 'string', 'description' => 'Your answer']))->not->toBeNull();
    });

    it('never asks a field whose rules check a password', function (mixed $rule) {
        expect(seams05Property('confirm_with', ['type' => 'string'], rules: ['required', $rule]))->toBeNull();
    })->with([
        'current_password' => 'current_password',
        'current_password with a guard' => 'current_password:web',
        'Password' => fn (): Password => Password::min(8),
    ]);

    it('never asks a key agents.forbidden_keys matches', function () {
        config(['agentic-actions.agents.forbidden_keys' => ['internal_*']]);

        expect(seams05Property('internal_note', ['type' => 'string']))->toBeNull()
            ->and(seams05Property('INTERNAL_NOTE', ['type' => 'string']))->toBeNull()
            ->and(seams05Property('note', ['type' => 'string']))->not->toBeNull();
    });
});

describe('the default', function () {
    it('takes the first candidate the field accepts, else the node\'s own', function (array $node, array $candidates, mixed $default) {
        $property = seams05Property('field', $node, candidates: $candidates)[0];

        expect(array_key_exists('default', $property) ? $property['default'] : 'NONE')->toBe($default);
    })->with([
        'the model\'s value' => [['type' => 'string'], ['Launch notes', 'Authored'], 'Launch notes'],
        'over maxLength, then the next' => [['type' => 'string', 'maxLength' => 5], ['Launch notes', 'Short'], 'Short'],
        'of the wrong type, then the next' => [['type' => 'integer'], ['5', 7], 7],
        'a float for an integer' => [['type' => 'integer'], [5.0], 'NONE'],
        'an integer for a number' => [['type' => 'number'], [5], 5],
        'outside the enum, then the next' => [['type' => 'string', 'enum' => ['draft', 'published']], ['archived', 'draft'], 'draft'],
        'a list with an item outside the enum' => [['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['news']]], [['news', 'gossip']], 'NONE'],
        'a map for a list' => [['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['news']]], [['a' => 'news']], 'NONE'],
        'the node\'s own' => [['type' => 'string', 'default' => 'Untitled'], [], 'Untitled'],
        'the node\'s own, after a refused candidate' => [['type' => 'boolean', 'default' => true], ['yes'], true],
        'none' => [['type' => 'string'], [], 'NONE'],
    ]);
});
