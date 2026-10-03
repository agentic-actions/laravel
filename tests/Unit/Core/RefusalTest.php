<?php

use AgenticActions\ActionsManager;
use AgenticActions\Refusal;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Support\Facades\Lang;

/**
 * Load JSON language lines for one locale from a temporary directory.
 *
 * @param  array<string, string>  $lines
 */
function jsonLines(string $locale, array $lines): void
{
    $directory = sys_get_temp_dir().'/agentic-actions-lang-'.bin2hex(random_bytes(4));

    mkdir($directory);
    file_put_contents("{$directory}/{$locale}.json", json_encode($lines));

    app('translator')->addJsonPath($directory);
}

it('translates an existing key with its replacements', function () {
    Lang::addLines(['refusals.duplicate' => 'You already have a :thing with that title.'], 'en');

    expect(Refusal::make('refusals.duplicate', null, ['thing' => 'post'])->translate())
        ->toBe('You already have a post with that title.');
});

it('falls back to the default sentence for a missing key, with replacements', function () {
    expect(Refusal::make('refusals.missing', 'No note named :name.', ['name' => 'Plans'])->translate())
        ->toBe('No note named Plans.');
});

it('translates a sentence through JSON language lines', function () {
    jsonLines('ar', ['No post by that name.' => 'لا يوجد منشور بهذا الاسم.']);

    expect(Refusal::make('No post by that name.')->translate('ar'))->toBe('لا يوجد منشور بهذا الاسم.')
        ->and(Refusal::make('No post by that name.')->translate('en'))->toBe('No post by that name.');
});

it('uses translateUsing() for app keys only', function () {
    app(ActionsManager::class)->translateUsing(
        fn (string $key, ?string $default, array $replace, string $locale): string => "[{$locale}] {$key} ".($replace['n'] ?? ''),
    );

    expect(Refusal::make('app.refused', null, ['n' => 3])->translate('ar'))->toBe('[ar] app.refused 3')
        ->and(Refusal::make('agentic-actions::http.denied')->translate('en'))->toBe(trans('agentic-actions::http.denied', [], 'en'));
});

it('builds a ValidationException on the field, or on "action", in the given bag', function () {
    $onField = Refusal::make('Taken.')->on('title')->toValidationException('createNote');
    $onAction = Refusal::make('Not now.')->toValidationException();

    expect($onField->errors())->toBe(['title' => ['Taken.']])
        ->and($onField->errorBag)->toBe('createNote')
        ->and($onAction->errors())->toBe(['action' => ['Not now.']])
        ->and($onAction->errorBag)->toBe('default');
});

it('has the documented defaults', function () {
    $refusal = Refusal::make('refusals.none', 'Nothing to do.');

    expect($refusal->statusCode())->toBe(409)
        ->and($refusal->field())->toBeNull()
        ->and($refusal->getDetails())->toBe([])
        ->and($refusal->getListing())->toBeNull()
        ->and($refusal->key())->toBe('refusals.none')
        ->and($refusal->getMessage())->toBe('Nothing to do.')
        ->and(Refusal::make('refusals.none')->getMessage())->toBe('refusals.none')
        ->and($refusal)->toBeInstanceOf(ShouldntReport::class);
});

it('carries its field, status, details and listing', function () {
    $refusal = Refusal::make('Busy.')->on('items.3')->status(423)->details(['retry' => 5])->listing(['a' => 'One', 'b' => 'Two']);

    expect($refusal->field())->toBe('items.3')
        ->and($refusal->statusCode())->toBe(423)
        ->and($refusal->getDetails())->toBe(['retry' => 5])
        ->and($refusal->getListing())->toBe(['One', 'Two']);
});

it('can be subclassed, and make() returns the subclass', function () {
    $refusal = PostRefusal::make('Nope.');

    expect($refusal)->toBeInstanceOf(PostRefusal::class)
        ->and($refusal->on('title'))->toBeInstanceOf(PostRefusal::class);
});

/**
 * An app's own refusal type.
 */
final class PostRefusal extends Refusal
{
    //
}
