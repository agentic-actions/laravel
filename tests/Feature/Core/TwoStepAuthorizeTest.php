<?php

use AgenticActions\ActionContext;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Pipeline\Authorizer;
use AgenticActions\Pipeline\AuthorizeTiming;
use AgenticActions\Pipeline\Door;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Runner;
use Illuminate\Support\Facades\Auth;
use Tests\Fixtures\Actions\LateAuthorize;
use Tests\Fixtures\Actions\Trace;
use Tests\Fixtures\Actions\TracedNote;
use Tests\Fixtures\Authorize\ReviewInput;
use Tests\Fixtures\Authorize\TwoStepAuthorize;
use Tests\Fixtures\Authorize\TwoStepAuthorizeOnSubclass;
use Tests\Fixtures\Authorize\TwoStepAuthorizeWithoutDefault;
use Tests\Fixtures\Authorize\TwoStepReview;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * An authorize() whose ValidatedInput may be null runs twice: before any input is read, with null (so a tool is listed
 * only to those it lets in), and after validation, with the input.
 */

beforeEach(function () {
    Trace::reset();
    TwoStepReview::$mayReview = true;
    Auth::shouldUse('web');

    $this->user = User::factory()->create();
});

dataset('nullable input', [
    '?ValidatedInput $input = null' => [TwoStepAuthorize::class],
    '?ValidatedInput $input' => [TwoStepAuthorizeWithoutDefault::class],
]);

it('reads a nullable ValidatedInput as both timings, and a required one as after validation only', function () {
    expect(Authorizer::timing(new TwoStepAuthorize))->toBe(AuthorizeTiming::Both)
        ->and(Authorizer::timing(new LateAuthorize))->toBe(AuthorizeTiming::Late)
        ->and(Authorizer::timing(new TracedNote))->toBe(AuthorizeTiming::Early);
});

it('runs it before the input is read and again after validation', function (string $class) {
    $own = Post::factory()->for($this->user)->create();

    $outcome = app(Runner::class)->run(ClassExposure::of($class), ['post_id' => $own->getKey()], ActionContext::http($this->user), Door::InProcess);

    expect($outcome->kind())->toBe(OutcomeKind::Ok)
        ->and(Trace::$calls)->toBe(['authorize:before', 'prepareForValidation', 'rules', 'authorize:after', 'handle'])
        ->and($own->fresh()->status)->toBe('reviewed');
})->with('nullable input');

it('stops before reading any input when the first check refuses', function (string $class) {
    TwoStepReview::$mayReview = false;
    $own = Post::factory()->for($this->user)->create();

    $outcome = app(Runner::class)->run(ClassExposure::of($class), ['post_id' => $own->getKey()], ActionContext::http($this->user), Door::InProcess);

    expect($outcome->kind())->toBe(OutcomeKind::Denied)
        ->and(Trace::$calls)->toBe(['authorize:before'])
        ->and($own->fresh()->status)->toBe('draft');
})->with('nullable input');

it('still refuses a record the second check denies', function (string $class) {
    $foreign = Post::factory()->create();

    $outcome = app(Runner::class)->run(ClassExposure::of($class), ['post_id' => $foreign->getKey()], ActionContext::http($this->user), Door::InProcess);

    expect($outcome->kind())->toBe(OutcomeKind::Denied)
        ->and(Trace::$calls)->toBe(['authorize:before', 'prepareForValidation', 'rules', 'authorize:after'])
        ->and($foreign->fresh()->status)->toBe('draft');
})->with('nullable input');

it('never lets a nullable subclass of ValidatedInput through on a record it denies: the input reaches it by name and fails its type', function () {
    $foreign = Post::factory()->create();

    $outcome = app(Runner::class)->run(ClassExposure::of(TwoStepAuthorizeOnSubclass::class), ['post_id' => $foreign->getKey()], ActionContext::http($this->user), Door::InProcess);

    expect($outcome->kind())->toBe(OutcomeKind::Failed)
        ->and($outcome->exception())->toBeInstanceOf(TypeError::class)
        ->and($outcome->exception()?->getMessage())->toContain('authorize(): Argument #2 ($input) must be of type ?'.ReviewInput::class)
        ->and(Trace::$calls)->toBe(['authorize:before', 'prepareForValidation', 'rules'])
        ->and($foreign->fresh()->status)->toBe('draft');
});

it('lists the tool only to those the first check lets in, without any input', function (string $class) {
    $runner = app(Runner::class);
    $entry = ClassExposure::of($class);

    expect($runner->exposed($entry, ActionContext::http($this->user), Door::InProcess))->toBeTrue()
        ->and(Trace::$calls)->toBe(['authorize:before']);

    TwoStepReview::$mayReview = false;

    expect($runner->exposed($entry, ActionContext::http($this->user), Door::InProcess))->toBeFalse();
})->with('nullable input');

it('keeps listing an input-only authorize() as before: it cannot be asked without input', function () {
    expect(app(Runner::class)->exposed(ClassExposure::of(LateAuthorize::class), ActionContext::http(User::factory()->create()), Door::InProcess))->toBeTrue()
        ->and(Trace::$calls)->toBe([]);
});
