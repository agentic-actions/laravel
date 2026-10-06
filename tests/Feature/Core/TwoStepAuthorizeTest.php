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
use Tests\Fixtures\Authorize\TwoStepAuthorize;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * An authorize() whose ValidatedInput may be null runs twice: before any input is read, with null (so a tool is listed
 * only to those it lets in), and after validation, with the input.
 */

beforeEach(function () {
    Trace::reset();
    TwoStepAuthorize::$mayReview = true;
    Auth::shouldUse('web');

    $this->user = User::factory()->create();
});

it('reads a nullable ValidatedInput as both timings, and a required one as after validation only', function () {
    expect(Authorizer::timing(new TwoStepAuthorize))->toBe(AuthorizeTiming::Both)
        ->and(Authorizer::timing(new LateAuthorize))->toBe(AuthorizeTiming::Late)
        ->and(Authorizer::timing(new TracedNote))->toBe(AuthorizeTiming::Early);
});

it('runs it before the input is read and again after validation', function () {
    $own = Post::factory()->for($this->user)->create();

    $outcome = app(Runner::class)->run(ClassExposure::of(TwoStepAuthorize::class), ['post_id' => $own->getKey()], ActionContext::http($this->user), Door::InProcess);

    expect($outcome->kind())->toBe(OutcomeKind::Ok)
        ->and(Trace::$calls)->toBe(['authorize:before', 'rules', 'authorize:after', 'handle'])
        ->and($own->fresh()->status)->toBe('reviewed');
});

it('stops before reading any input when the first check refuses', function () {
    TwoStepAuthorize::$mayReview = false;
    $own = Post::factory()->for($this->user)->create();

    $outcome = app(Runner::class)->run(ClassExposure::of(TwoStepAuthorize::class), ['post_id' => $own->getKey()], ActionContext::http($this->user), Door::InProcess);

    expect($outcome->kind())->toBe(OutcomeKind::Denied)
        ->and(Trace::$calls)->toBe(['authorize:before'])
        ->and($own->fresh()->status)->toBe('draft');
});

it('still refuses a record the second check denies', function () {
    $foreign = Post::factory()->create();

    $outcome = app(Runner::class)->run(ClassExposure::of(TwoStepAuthorize::class), ['post_id' => $foreign->getKey()], ActionContext::http($this->user), Door::InProcess);

    expect($outcome->kind())->not->toBe(OutcomeKind::Ok)
        ->and($foreign->fresh()->status)->toBe('draft');
});

it('lists the tool only to those the first check lets in, without any input', function () {
    $runner = app(Runner::class);
    $entry = ClassExposure::of(TwoStepAuthorize::class);

    expect($runner->exposed($entry, ActionContext::http($this->user), Door::InProcess))->toBeTrue()
        ->and(Trace::$calls)->toBe(['authorize:before']);

    TwoStepAuthorize::$mayReview = false;

    expect($runner->exposed($entry, ActionContext::http($this->user), Door::InProcess))->toBeFalse();
});

it('keeps listing an input-only authorize() as before: it cannot be asked without input', function () {
    expect(app(Runner::class)->exposed(ClassExposure::of(LateAuthorize::class), ActionContext::http(User::factory()->create()), Door::InProcess))->toBeTrue()
        ->and(Trace::$calls)->toBe([]);
});
