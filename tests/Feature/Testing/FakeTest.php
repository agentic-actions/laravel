<?php

use AgenticActions\ActionContext;
use AgenticActions\Events\ActionCompleted;
use AgenticActions\Events\ActionFailed;
use AgenticActions\Events\ActionRefused;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Facades\Actions;
use AgenticActions\Pipeline\Door;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Refusal;
use AgenticActions\Runner;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Fixtures\Actions\CrashingNote;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\DeleteNote;
use Tests\Fixtures\Actions\ListNotes;
use Tests\Fixtures\Actions\PlainNote;
use Tests\Fixtures\Actions\ReadThatWrites;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->context = ActionContext::http($this->user);
});

it('answers an unlisted action with an empty success and never runs it', function () {
    Actions::fake();

    $outcome = Actions::attempt(CreateNote::class, ['title' => 'Hi', 'body' => 'x'], $this->context);

    expect(CreateNote::run(['title' => 'Hi', 'body' => 'x'], $this->context))->toBeNull()
        ->and($outcome->ok())->toBeTrue()
        ->and($outcome->result())->toBeNull()
        ->and($outcome->output())->toBe([])
        ->and(Post::query()->count())->toBe(0);
});

it('returns a listed array as the result and the output', function () {
    Actions::fake([CreateNote::class => ['id' => 1, 'title' => 'Hi']]);

    $outcome = Actions::attempt(CreateNote::class, ['title' => 'Hi', 'body' => 'x'], $this->context);

    expect(CreateNote::run(['title' => 'Hi', 'body' => 'x'], $this->context))->toBe(['id' => 1, 'title' => 'Hi'])
        ->and($outcome->kind())->toBe(OutcomeKind::Ok)
        ->and($outcome->result())->toBe(['id' => 1, 'title' => 'Hi'])
        ->and($outcome->output())->toBe(['id' => 1, 'title' => 'Hi'])
        ->and($outcome->status())->toBe(200)
        ->and(Post::query()->count())->toBe(0);
});

it('turns a listed Refusal into a refused outcome, which run() throws', function () {
    $refusal = Refusal::make('Not ready to publish.');
    Actions::fake([CreateNote::class => $refusal]);

    $outcome = Actions::attempt(CreateNote::class, ['title' => 'Hi', 'body' => 'x'], $this->context);

    expect($outcome->kind())->toBe(OutcomeKind::Refused)
        ->and($outcome->refusal())->toBe($refusal)
        ->and($outcome->status())->toBe(409)
        ->and(fn () => CreateNote::run(['title' => 'Hi', 'body' => 'x'], $this->context))
        ->toThrow(Refusal::class, 'Not ready to publish.');
});

it('calls a listed closure with the input and the context', function () {
    $seen = [];

    Actions::fake([
        ListNotes::class => function (array $input, ActionContext $context) use (&$seen): array {
            $seen[] = [$input, $context->actor?->getAuthIdentifier()];

            return ['posts' => []];
        },
    ]);

    $outcome = Actions::attempt(ListNotes::class, ['page' => 2], $this->context);

    expect($outcome->result())->toBe(['posts' => []])
        ->and($outcome->output())->toBe(['posts' => []])
        ->and($seen)->toBe([[['page' => 2], $this->user->getKey()]]);
});

it('keeps a closure\'s non-array result as the result only', function () {
    $post = new Post(['title' => 'Hi']);
    Actions::fake([CreateNote::class => fn (): Post => $post]);

    $outcome = Actions::attempt(CreateNote::class, [], $this->context);

    expect(CreateNote::run([], $this->context))->toBe($post)
        ->and($outcome->result())->toBe($post)
        ->and($outcome->output())->toBe([]);
});

it('turns a Refusal thrown by a closure into a refused outcome', function () {
    Actions::fake([
        CreateNote::class => fn (array $input): array => $input['title'] === 'Taken'
            ? throw Refusal::make('You already have a note with that title.')->on('title')
            : ['id' => 1, 'title' => $input['title']],
    ]);

    expect(Actions::attempt(CreateNote::class, ['title' => 'Fresh'], $this->context)->output())->toBe(['id' => 1, 'title' => 'Fresh']);

    $refused = Actions::attempt(CreateNote::class, ['title' => 'Taken'], $this->context);

    expect($refused->kind())->toBe(OutcomeKind::Refused)
        ->and($refused->refusal()?->field())->toBe('title')
        ->and($refused->status())->toBe(422);
});

it('lets a crash inside a closure propagate', function () {
    Actions::fake([CreateNote::class => fn () => throw new RuntimeException('A broken fake.')]);

    expect(fn () => Actions::attempt(CreateNote::class, [], $this->context))->toThrow(RuntimeException::class, 'A broken fake.');
});

it('skips every gate, authorize() and handle()', function () {
    Actions::fake();

    $runner = app(Runner::class);

    // Not exposed on a generated route, no actor for a guest-refusing Read, a Destructive action on the agent door,
    // a Read that writes, and a crash: each one answers the fake's success.
    expect($runner->run(ClassExposure::of(PlainNote::class), [], $this->context, Door::GeneratedRoute)->ok())->toBeTrue()
        ->and($runner->run(ClassExposure::of(ListNotes::class), [], ActionContext::agent(null), Door::InProcess)->ok())->toBeTrue()
        ->and($runner->run(ClassExposure::of(DeleteNote::class), [], ActionContext::agent($this->user), Door::Agent, ['default'])->ok())->toBeTrue()
        ->and(ReadThatWrites::run([], $this->context))->toBeNull()
        ->and(CrashingNote::run([], $this->context))->toBeNull()
        ->and(Post::query()->count())->toBe(0);
});

it('fires no event', function () {
    Event::fake([ActionCompleted::class, ActionRefused::class, ActionFailed::class]);
    Actions::fake([PlainNote::class => Refusal::make('No.')]);

    Actions::attempt(CreateNote::class, [], $this->context);
    Actions::attempt(PlainNote::class, [], $this->context);

    Event::assertNothingDispatched();
});

it('asserts that an action ran, by class or by name, with a callback', function () {
    $fake = Actions::fake();

    CreateNote::run(['title' => 'Hi', 'body' => 'x'], $this->context);

    $fake->assertRan(CreateNote::class);
    $fake->assertRan('create-note');
    $fake->assertRan('\\'.CreateNote::class);
    $fake->assertRan(CreateNote::class, fn (array $input, ActionContext $context): bool => $input['title'] === 'Hi' && $context->actor === $this->user);

    expect(fn () => $fake->assertRan(ListNotes::class))
        ->toThrow(ExpectationFailedException::class, 'The expected action ['.ListNotes::class.'] did not run.')
        ->and(fn () => $fake->assertRan('create-note', fn (array $input): bool => $input['title'] === 'Bye'))
        ->toThrow(ExpectationFailedException::class, 'The expected action [create-note] did not run with input and context the callback accepts.');
});

it('asserts that an action did not run', function () {
    $fake = Actions::fake();

    CreateNote::run(['title' => 'Hi'], $this->context);

    $fake->assertNotRan(ListNotes::class);
    $fake->assertNotRan('list-notes');
    $fake->assertNotRan(CreateNote::class, fn (array $input): bool => $input['title'] === 'Bye');

    expect(fn () => $fake->assertNotRan('create-note'))
        ->toThrow(ExpectationFailedException::class, 'The unexpected action [create-note] ran.')
        ->and(fn () => $fake->assertNotRan(CreateNote::class, fn (array $input): bool => $input['title'] === 'Hi'))
        ->toThrow(ExpectationFailedException::class, 'ran with input and context the callback accepts.');
});

it('counts the runs of one action', function () {
    $fake = Actions::fake();

    CreateNote::run(['title' => 'One'], $this->context);
    CreateNote::run(['title' => 'Two'], $this->context);
    ListNotes::run([], $this->context);

    $fake->assertRanTimes(CreateNote::class, 2);
    $fake->assertRanTimes('list-notes', 1);
    $fake->assertRanTimes(PlainNote::class, 0);

    expect(fn () => $fake->assertRanTimes('create-note', 3))
        ->toThrow(ExpectationFailedException::class, 'The expected action [create-note] ran 2 times instead of 3 times.');
});

it('asserts that nothing ran', function () {
    $fake = Actions::fake();

    $fake->assertNothingRan();

    CreateNote::run([], $this->context);
    ListNotes::run([], $this->context);

    expect(fn () => $fake->assertNothingRan())
        ->toThrow(ExpectationFailedException::class, 'Actions ran unexpectedly: create-note, list-notes.');
});

it('fails loudly for a name no action has', function () {
    $fake = Actions::fake();

    expect(fn () => $fake->assertNotRan('crate-note'))
        ->toThrow(AssertionFailedError::class, 'No action is named [crate-note].')
        ->and(fn () => $fake->assertRanTimes('crate-note', 0))
        ->toThrow(AssertionFailedError::class, 'No action is named [crate-note].');
});

it('records the context each door hands the fake', function () {
    $fake = Actions::fake();

    app(Runner::class)->run(ClassExposure::of(CreateNote::class), ['title' => 'Hi'], ActionContext::http($this->user), Door::Agent, ['default']);

    $fake->assertRan(CreateNote::class, fn (array $input, ActionContext $context): bool => $context->isModelDriven());
});
