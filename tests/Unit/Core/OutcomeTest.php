<?php

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Exposure\Entry;
use AgenticActions\Outcome;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Refusal;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\ListNotes;

/**
 * An entry for a write, and a context in the given locale.
 *
 * @return array{0: Entry, 1: ActionContext}
 */
function outcomeParts(string $locale = 'en', string $class = CreateNote::class): array
{
    return [ClassExposure::of($class), ActionContext::agent(null, null, $locale)];
}

it('describes a success', function () {
    [$entry, $context] = outcomeParts();
    $outcome = Outcome::completed($entry, $context, 'raw', ['id' => 1], null);

    expect($outcome->ok())->toBeTrue()
        ->and($outcome->refused())->toBeFalse()
        ->and($outcome->status())->toBe(200)
        ->and($outcome->output())->toBe(['id' => 1])
        ->and($outcome->result())->toBe('raw')
        ->and($outcome->refusal())->toBeNull()
        ->and($outcome->kind())->toBe(OutcomeKind::Ok)
        ->and($outcome->entry())->toBe($entry)
        ->and($outcome->context())->toBe($context);
});

it('builds a refusal for each kind that is not ok', function () {
    [$entry, $context] = outcomeParts();
    $own = Refusal::make('Busy.')->status(423);

    expect(Outcome::invalid($entry, $context, ['title' => ['Too long.']], [])->refusal())
        ->key()->toBe('agentic-actions::http.invalid')
        ->statusCode()->toBe(422)
        ->and(Outcome::notFound($entry, $context)->refusal())
        ->key()->toBe('agentic-actions::http.not_found')
        ->statusCode()->toBe(404)
        ->and(Outcome::denied($entry, $context)->refusal())
        ->key()->toBe('agentic-actions::http.denied')
        ->statusCode()->toBe(403)
        ->and(Outcome::refusedBy($entry, $context, $own)->refusal())->toBe($own)
        ->and(Outcome::failed($entry, $context, new RuntimeException('x'))->refusal())->toBeNull();
});

it('answers the status HTTP would', function () {
    [$entry, $context] = outcomeParts();

    expect(Outcome::invalid($entry, $context, [], [])->status())->toBe(422)
        ->and(Outcome::notFound($entry, $context)->status())->toBe(404)
        ->and(Outcome::denied($entry, $context)->status())->toBe(403)
        ->and(Outcome::refusedBy($entry, $context, Refusal::make('Busy.'))->status())->toBe(409)
        ->and(Outcome::refusedBy($entry, $context, Refusal::make('Key.')->status(428))->status())->toBe(428)
        ->and(Outcome::refusedBy($entry, $context, Refusal::make('Taken.')->on('title'))->status())->toBe(422)
        ->and(Outcome::failed($entry, $context, new RuntimeException('x'))->status())->toBe(500);
});

it('has no output unless ok', function () {
    [$entry, $context] = outcomeParts();

    expect(Outcome::denied($entry, $context)->output())->toBeNull()
        ->and(Outcome::denied($entry, $context)->refused())->toBeTrue()
        ->and(Outcome::completed($entry, $context, null, [], null)->output())->toBe([]);
});

it('tells a model what happened, in its locale', function (string $locale, array $sentences) {
    [$write, $context] = outcomeParts($locale);
    [$read] = outcomeParts($locale, ListNotes::class);

    expect(Outcome::completed($write, $context, null, ['id' => 1], null)->forModel())->toBe($sentences['done'])
        ->and(Outcome::completed($write, $context, null, ['id' => 1], 'Saved the note.')->forModel())->toBe('Saved the note.')
        ->and(Outcome::completed($read, $context, null, ['posts' => [['id' => 1, 'title' => 'Héllo/1']]], null)->forModel())
        ->toBe($sentences['found']."\n---\n".'{"posts":[{"id":1,"title":"Héllo/1"}]}')
        ->and(Outcome::completed($read, $context, null, ['posts' => []], 'Here are the notes.')->forModel())
        ->toBe("Here are the notes.\n---\n".'{"posts":[]}')
        ->and(Outcome::invalid($write, $context, ['title' => ['The title is too long.'], 'body' => ['Required.']], ['title' => ['max'], 'body' => ['required']])->forModel())
        ->toBe(str_replace(':fields', 'title (max), body (required)', $sentences['rejected']))
        ->and(Outcome::notFound($write, $context)->forModel())->toBe($sentences['not_found'])
        ->and(Outcome::denied($write, $context)->forModel())->toBe($sentences['denied'])
        ->and(Outcome::failed($write, $context, new RuntimeException('secret detail'))->forModel())->toBe($sentences['failed']);
})->with([
    'en' => ['en', [
        'done' => 'Done.',
        'found' => 'Found.',
        'rejected' => 'Not done. Rejected: :fields.',
        'not_found' => 'Not done: that action is not available here. Do not try it again.',
        'denied' => 'Not done: this person is not allowed to do that. Stop and tell them.',
        'failed' => 'Not done: something went wrong on the server. Tell the person it did not finish.',
    ]],
    'ar' => ['ar', [
        'done' => 'تم.',
        'found' => 'هذا ما وُجد.',
        'rejected' => 'لم يتم. رُفضت الحقول: :fields.',
        'not_found' => 'لم يتم: هذا الإجراء غير متاح هنا. لا تحاول مرة أخرى.',
        'denied' => 'لم يتم: لا يُسمح لهذا الشخص بذلك. توقّف وأخبره.',
        'failed' => 'لم يتم: حدث خطأ في الخادم. أخبر الشخص أن العملية لم تكتمل.',
    ]],
]);

it('frames a refusal\'s listing as data and never sends its details', function () {
    [$entry, $context] = outcomeParts();

    $refusal = Refusal::make('No note by that title.')->on('title')->listing(['Plans', 'Notes/2'])->details(['secret' => 'x']);

    expect(Outcome::refusedBy($entry, $context, $refusal)->forModel())
        ->toBe("No note by that title.\n---\n".'["Plans","Notes/2"]')
        ->and(Outcome::refusedBy($entry, $context, Refusal::make('Busy.'))->forModel())->toBe('Busy.');
});

it('names a key with no failed rule alone, or with its message when the action opts in', function () {
    [$entry, $context] = outcomeParts();

    $optIn = new class extends Action
    {
        protected bool $validationMessagesToModel = true;
    };

    $optedIn = Entry::fromClass($optIn::class);

    expect(Outcome::invalid($entry, $context, ['title' => ['Pick another title.']], ['title' => []])->forModel())
        ->toBe('Not done. Rejected: title.')
        ->and(Outcome::invalid($optedIn, $context, ['title' => ['Pick another title.']], ['title' => []])->forModel())
        ->toBe('Not done. Rejected: title: Pick another title..')
        ->and(Outcome::invalid($optedIn, $context, ['title' => ['Too long.']], ['title' => ['max']])->forModel())
        ->toBe('Not done. Rejected: title (max).');
});
