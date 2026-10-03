<?php

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\ActionsManager;
use AgenticActions\Effect;
use AgenticActions\Events\ActionCompleted;
use AgenticActions\Events\ActionFailed;
use AgenticActions\Events\ActionRefused;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Exposure\Entry;
use AgenticActions\MissingContext;
use AgenticActions\Outcome;
use AgenticActions\Pipeline\Door;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Refusal;
use AgenticActions\Runner;
use AgenticActions\Surface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\ValidatedInput;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Gateway\ParentInvocation;
use Tests\Fixtures\Actions\ChildNote;
use Tests\Fixtures\Actions\CrashingNote;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\DeleteNote;
use Tests\Fixtures\Actions\GuestLookup;
use Tests\Fixtures\Actions\HiddenNote;
use Tests\Fixtures\Actions\LateAuthorize;
use Tests\Fixtures\Actions\ListNotes;
use Tests\Fixtures\Actions\NamedRuleNote;
use Tests\Fixtures\Actions\PlainNote;
use Tests\Fixtures\Actions\RefusingNote;
use Tests\Fixtures\Actions\Trace;
use Tests\Fixtures\Actions\TracedNote;
use Tests\Fixtures\Actions\TranslatedNote;
use Tests\Fixtures\Misconfigured\NoAuthorize;
use Tests\Fixtures\Misconfigured\UndeclaredEffect;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

beforeEach(function () {
    Trace::reset();
    TracedNote::reset();
    Auth::shouldUse('web');

    $this->user = User::factory()->create();
});

/**
 * Run an action through the Runner on a door.
 *
 * @param  class-string<Action>  $class
 * @param  array<string, mixed>  $input
 * @param  list<string>  $toolsets
 */
function runThrough(string $class, array $input, ActionContext $context, Door $door = Door::InProcess, array $toolsets = []): Outcome
{
    return app(Runner::class)->run(ClassExposure::of($class), $input, $context, $door, $toolsets);
}

describe('the class door (step 1a)', function () {
    it('refuses the agent door without the agent surface, the switch or a shared toolset', function () {
        $this->skipUnlessAi();

        $context = ActionContext::agent($this->user);
        $input = ['title' => 'Hi', 'body' => 'x'];

        expect(runThrough(CreateNote::class, $input, $context, Door::Agent, ['default'])->kind())->toBe(OutcomeKind::Ok)
            ->and(runThrough(CreateNote::class, $input, $context, Door::Agent, ['support'])->kind())->toBe(OutcomeKind::NotFound)
            ->and(runThrough(TracedNote::class, ['title' => 'Hi'], $context, Door::Agent, ['default'])->kind())->toBe(OutcomeKind::NotFound)
            ->and(runThrough(PlainNote::class, ['title' => 'Hi'], $context, Door::Agent, ['default'])->kind())->toBe(OutcomeKind::NotFound)
            ->and(runThrough(ChildNote::class, $input, $context, Door::Agent, ['default'])->kind())->toBe(OutcomeKind::NotFound)
            ->and(runThrough(UndeclaredEffect::class, [], $context, Door::Agent, ['default'])->kind())->toBe(OutcomeKind::NotFound);

        config(['agentic-actions.surfaces.agents' => false]);

        expect(runThrough(CreateNote::class, $input, $context, Door::Agent, ['default'])->kind())->toBe(OutcomeKind::NotFound);
    });
});

describe('the model gate (step 1b)', function () {
    it('refuses a model an effect that is not model-safe', function () {
        expect(runThrough(DeleteNote::class, ['note' => 1], ActionContext::agent($this->user))->kind())->toBe(OutcomeKind::NotFound)
            ->and(runThrough(DeleteNote::class, ['note' => 1], ActionContext::http($this->user))->kind())->toBe(OutcomeKind::Ok);
    });

    it('refuses a model-driven guest unless the action sets $guests', function () {
        expect(runThrough(ListNotes::class, [], ActionContext::agent(null))->kind())->toBe(OutcomeKind::NotFound)
            ->and(runThrough(TracedNote::class, ['title' => 'Hi'], ActionContext::agent(null))->kind())->toBe(OutcomeKind::NotFound)
            ->and(Trace::$calls)->toBe([]);

        $outcome = runThrough(GuestLookup::class, [], ActionContext::agent(null));

        expect($outcome->kind())->toBe(OutcomeKind::Ok)
            ->and($outcome->result())->toBe('looked up');
    });

    it('runs the agent door as the Agent surface, whatever context it is handed', function () {
        $this->skipUnlessAi();

        $candidate = ClassExposure::of(CreateNote::class);
        $outcome = runThrough(CreateNote::class, ['title' => 'Hi', 'body' => 'x'], ActionContext::http(null), Door::Agent, ['default']);

        expect($outcome->kind())->toBe(OutcomeKind::NotFound)
            ->and($outcome->context()->surface)->toBe(Surface::Agent)
            ->and(app(Runner::class)->exposed($candidate, ActionContext::http(null), Door::Agent, ['default']))->toBeFalse()
            ->and(app(Runner::class)->exposed($candidate, ActionContext::http($this->user), Door::Agent, ['default']))->toBeTrue()
            ->and(runThrough(CreateNote::class, ['title' => 'Hi', 'body' => 'x'], ActionContext::system(), Door::Agent, ['default'])->kind())->toBe(OutcomeKind::NotFound);
    });

    it('treats a call made inside a laravel/ai tool as model-driven, whatever context it was given', function () {
        $this->skipUnlessAi();

        $inside = ParentInvocation::within('invocation-1', 'tool-invocation-1', fn () => runThrough(DeleteNote::class, [], ActionContext::http($this->user)));
        $outside = runThrough(DeleteNote::class, [], ActionContext::http($this->user));

        expect($inside->kind())->toBe(OutcomeKind::NotFound)
            ->and($outside->kind())->toBe(OutcomeKind::Invalid);
    });

    it('treats a call made inside a laravel/mcp request as model-driven', function () {
        $console = ActionContext::console($this->user, null, 'en', null);

        expect(runThrough(DeleteNote::class, [], $console)->kind())->toBe(OutcomeKind::Invalid);

        app()->instance('mcp.request', new stdClass);

        expect(runThrough(DeleteNote::class, [], $console)->kind())->toBe(OutcomeKind::NotFound);
    });
});

describe('the token and exposure hook (steps 1c and 1d)', function () {
    it('refuses a credential the token check refuses, before shouldRegister()', function () {
        Auth::viaRequest('api-key', fn () => $this->user);
        config(['auth.guards.api-key' => ['driver' => 'api-key']]);
        Auth::shouldUse('api-key');

        expect(runThrough(TracedNote::class, ['title' => 'Hi'], ActionContext::http($this->user))->kind())->toBe(OutcomeKind::NotFound)
            ->and(Trace::$calls)->toBe([]);
    });

    it('reads shouldRegister() false exactly like an unknown action', function () {
        TracedNote::$registers = false;

        expect(runThrough(TracedNote::class, ['title' => 'Hi'], ActionContext::http($this->user))->kind())->toBe(OutcomeKind::NotFound)
            ->and(Trace::$calls)->toBe(['shouldRegister']);

        Trace::reset();

        expect(runThrough(HiddenNote::class, [], ActionContext::http($this->user))->kind())->toBe(OutcomeKind::NotFound)
            ->and(Trace::$calls)->toBe(['shouldRegister']);
    });
});

describe('the tenant scope (step 2)', function () {
    beforeEach(fn () => $this->useTeamTenancy());

    it('fails with MissingContext when a tenant-scoped action has no tenant', function () {
        Exceptions::fake();

        $outcome = runThrough(TracedNote::class, ['title' => 'Hi'], ActionContext::http($this->user));

        expect($outcome->kind())->toBe(OutcomeKind::Failed)
            ->and($outcome->exception())->toBeInstanceOf(MissingContext::class)
            ->and(Trace::$calls)->toBe(['shouldRegister']);

        Exceptions::assertReported(MissingContext::class);
    });

    it('refuses a non-member, and an actor-less context outside system work', function () {
        $team = Team::factory()->create();

        expect(runThrough(TracedNote::class, ['title' => 'Hi'], ActionContext::http($this->user, $team))->kind())->toBe(OutcomeKind::NotFound)
            ->and(Trace::$calls)->toBe(['shouldRegister']);

        $team->users()->attach($this->user);

        expect(runThrough(TracedNote::class, ['title' => 'Hi'], ActionContext::http($this->user, $team))->kind())->toBe(OutcomeKind::Ok)
            ->and(runThrough(TracedNote::class, ['title' => 'Hi'], ActionContext::http(null, $team))->kind())->toBe(OutcomeKind::NotFound);
    });

    it('passes the effect to membership', function () {
        $archived = Team::factory()->create(['slug' => 'archived-2025']);
        $archived->users()->attach($this->user);

        expect(runThrough(TracedNote::class, ['title' => 'Hi'], ActionContext::http($this->user, $archived))->kind())->toBe(OutcomeKind::NotFound);
    });

    it('skips membership for system work with no actor', function () {
        $team = Team::factory()->create();

        expect(runThrough(TracedNote::class, ['title' => 'Hi'], ActionContext::system($team))->kind())->toBe(OutcomeKind::Ok)
            ->and(Trace::$calls)->toBe(['shouldRegister', 'authorize', 'prepareForValidation', 'rules', 'handle', 'modelReply']);
    });
});

describe('authorization (steps 3 and 7)', function () {
    it('denies an action without authorize() everywhere', function () {
        expect(runThrough(NoAuthorize::class, [], ActionContext::http($this->user))->kind())->toBe(OutcomeKind::Denied)
            ->and(runThrough(NoAuthorize::class, [], ActionContext::http($this->user), Door::GeneratedRoute)->kind())->toBe(OutcomeKind::Denied)
            ->and(runThrough(NoAuthorize::class, [], ActionContext::system())->kind())->toBe(OutcomeKind::Denied);
    });

    it('runs an input-free authorize() before prepareForValidation()', function (mixed $answer, OutcomeKind $kind) {
        TracedNote::$authorizes = $answer;

        expect(runThrough(TracedNote::class, ['title' => str_repeat('x', 30)], ActionContext::http($this->user))->kind())->toBe($kind)
            ->and(Trace::$calls)->toBe(['shouldRegister', 'authorize']);
    })->with([
        'false' => [false, OutcomeKind::Denied],
        'null' => [null, OutcomeKind::Denied],
        'a denied response' => [fn () => Response::deny('No.'), OutcomeKind::Denied],
        'a 404 response' => [fn () => Response::denyAsNotFound(), OutcomeKind::NotFound],
        'a thrown 403' => [fn () => throw new AuthorizationException('No.'), OutcomeKind::Denied],
        'a thrown 404' => [fn () => throw (new AuthorizationException('No.'))->withStatus(404), OutcomeKind::NotFound],
        'a missing row' => [fn () => throw new ModelNotFoundException, OutcomeKind::NotFound],
        'a refusal' => [fn () => throw Refusal::make('Not today.'), OutcomeKind::Refused],
    ]);

    it('reads no row before an input-free authorize() answers', function () {
        TracedNote::$authorizes = false;

        DB::enableQueryLog();

        expect(runThrough(TracedNote::class, ['title' => 'Hi'], ActionContext::http($this->user))->kind())->toBe(OutcomeKind::Denied)
            ->and(DB::getQueryLog())->toBe([]);
    });

    it('lets an allowed response through', function () {
        TracedNote::$authorizes = Response::allow();

        expect(runThrough(TracedNote::class, ['title' => 'Hi'], ActionContext::http($this->user))->kind())->toBe(OutcomeKind::Ok);
    });

    it('runs an input-taking authorize() after validation', function () {
        $own = Post::factory()->for($this->user)->create();
        $foreign = Post::factory()->create();
        $context = ActionContext::http($this->user);

        expect(runThrough(LateAuthorize::class, ['post_id' => 'nope'], $context)->kind())->toBe(OutcomeKind::Invalid)
            ->and(Trace::$calls)->toBe(['prepareForValidation', 'rules']);

        Trace::reset();

        expect(runThrough(LateAuthorize::class, ['post_id' => $foreign->getKey()], $context)->kind())->toBe(OutcomeKind::Denied)
            ->and(Trace::$calls)->toBe(['prepareForValidation', 'rules', 'authorize'])
            ->and($foreign->fresh()->status)->toBe('draft');

        Trace::reset();

        expect(runThrough(LateAuthorize::class, ['post_id' => (string) $own->getKey()], $context)->kind())->toBe(OutcomeKind::Ok)
            ->and(Trace::$calls)->toBe(['prepareForValidation', 'rules', 'authorize', 'handle'])
            ->and($own->fresh()->status)->toBe('reviewed');
    });
});

describe('validation (steps 5 and 6)', function () {
    it('stops invalid input before handle()', function () {
        $outcome = runThrough(TracedNote::class, ['title' => str_repeat('x', 30)], ActionContext::http($this->user));

        expect($outcome->kind())->toBe(OutcomeKind::Invalid)
            ->and($outcome->errors())->toHaveKey('title')
            ->and($outcome->failedRules())->toBe(['title' => ['max']])
            ->and(Trace::$calls)->toBe(['shouldRegister', 'authorize', 'prepareForValidation', 'rules']);
    });

    it('reads model rule names from the validator and named rules', function () {
        $outcome = runThrough(NamedRuleNote::class, ['name' => 'Cher'], ActionContext::agent($this->user));

        expect($outcome->failedRules())->toBe(['name' => ['person_name']])
            ->and($outcome->forModel())->toBe(trans('agentic-actions::model.rejected', ['fields' => 'name (person_name)']));

        $missing = runThrough(NamedRuleNote::class, [], ActionContext::agent($this->user));

        expect($missing->failedRules())->toBe(['name' => ['required']])
            ->and(runThrough(CountNote::class, ['count' => INF], ActionContext::agent($this->user))->failedRules())->toBe(['count' => ['numeric']]);
    });

    it('overlays fixed input on the keys schema() declares, then coerces', function () {
        TracedNote::$handles = fn (ValidatedInput $input): array => $input->all();

        $context = ActionContext::http($this->user)->withFixed(['title' => 'Fixed', 'other' => 'ignored']);
        $outcome = runThrough(TracedNote::class, ['title' => 'From the body'], $context);

        expect($outcome->result())->toBe(['title' => 'Fixed']);
    });

    it('keeps a list item none of whose declared keys was given in its place, as an empty object, and an app\'s own map in its order', function () {
        $contexts = [ActionContext::system(), ActionContext::http($this->user), ActionContext::console($this->user, null, 'en', null), ActionContext::agent($this->user)];

        foreach ($contexts as $context) {
            expect(runThrough(ItemsNote::class, ['items' => [[], ['note' => 'A']], 'maybe' => [[], null, ['note' => 'B'], ['tags' => []]]], $context)->result())
                ->toBe(['items' => [[], ['note' => 'A']], 'maybe' => [[], null, ['note' => 'B'], ['tags' => []]]])
                ->and(runThrough(ItemsNote::class, ['items' => [['note' => 'A']], 'scores' => [3 => 'c', 1 => 'a']], $context)->result()['scores'])
                ->toBe([3 => 'c', 1 => 'a']);
        }
    });

    it('coerces form-style input on HTTP before strict rules', function () {
        $post = Post::factory()->for($this->user)->create();

        expect(runThrough(LateAuthorize::class, ['post_id' => (string) $post->getKey()], ActionContext::http($this->user))->kind())->toBe(OutcomeKind::Ok)
            ->and(runThrough(LateAuthorize::class, ['post_id' => '1.0'], ActionContext::http($this->user))->kind())->toBe(OutcomeKind::Invalid);
    });
});

describe('exceptions from handle() (step 8)', function () {
    it('maps each throwable to its kind', function (Closure $throw, OutcomeKind $kind) {
        TracedNote::$handles = $throw;

        expect(runThrough(TracedNote::class, ['title' => 'Hi'], ActionContext::http($this->user))->kind())->toBe($kind);
    })->with([
        'a refusal' => [fn () => throw Refusal::make('No.'), OutcomeKind::Refused],
        'a validation exception' => [fn () => throw ValidationException::withMessages(['title' => ['Taken.']]), OutcomeKind::Invalid],
        'a missing row' => [fn () => throw new ModelNotFoundException, OutcomeKind::NotFound],
        'an authorization exception' => [fn () => throw new AuthorizationException, OutcomeKind::Denied],
        'a 404 authorization exception' => [fn () => throw (new AuthorizationException)->withStatus(404), OutcomeKind::NotFound],
    ]);

    it('keeps a validation exception\'s keys, with no rule names', function () {
        TracedNote::$handles = fn () => throw ValidationException::withMessages(['title' => ['Pick another title.']]);

        $outcome = runThrough(TracedNote::class, ['title' => 'Hi'], ActionContext::agent($this->user));

        expect($outcome->errors())->toBe(['title' => ['Pick another title.']])
            ->and($outcome->failedRules())->toBe(['title' => []])
            ->and($outcome->forModel())->toBe(trans('agentic-actions::model.rejected', ['fields' => 'title']));
    });

    it('maps an app exception through Actions::refuse()', function () {
        app(ActionsManager::class)->refuse(DomainException::class, fn (DomainException $exception, ActionContext $context): Refusal => Refusal::make('Mapped: '.$exception->getMessage()));

        TracedNote::$handles = fn () => throw new DomainException('quota');

        $outcome = runThrough(TracedNote::class, ['title' => 'Hi'], ActionContext::http($this->user));

        expect($outcome->kind())->toBe(OutcomeKind::Refused)
            ->and($outcome->refusal()?->key())->toBe('Mapped: quota');
    });

    it('never maps MissingContext to a refusal', function () {
        Exceptions::fake();
        app(ActionsManager::class)->refuse(LogicException::class, fn (): Refusal => Refusal::make('Mapped.'));

        TracedNote::$handles = fn () => throw MissingContext::actor(User::class);

        expect(app(ActionsManager::class)->attempt(TracedNote::class, ['title' => 'Hi'], ActionContext::http($this->user))->kind())
            ->toBe(OutcomeKind::Failed);
    });
});

describe('the result (step 9)', function () {
    it('projects the output and asks for the model reply', function () {
        TracedNote::$handles = fn (): array => ['title' => 'Hi', 'secret' => 'never leaves'];

        $outcome = runThrough(TracedNote::class, ['title' => 'Hi'], ActionContext::http($this->user));

        expect($outcome->output())->toBe(['title' => 'Hi'])
            ->and($outcome->result())->toBe(['title' => 'Hi', 'secret' => 'never leaves'])
            ->and(Trace::$calls)->toBe(['shouldRegister', 'authorize', 'prepareForValidation', 'rules', 'handle', 'modelReply']);
    });

    it('runs every step in the context\'s locale, and restores the app\'s', function () {
        TracedNote::$handles = fn (): string => app()->getLocale();

        app()->setLocale('en');

        expect(runThrough(TracedNote::class, ['title' => 'Hi'], ActionContext::http($this->user, null, 'ar'))->result())->toBe('ar')
            ->and(app()->getLocale())->toBe('en');
    });

    it('builds a fresh action instance per run', function () {
        runThrough(TracedNote::class, ['title' => 'Hi'], ActionContext::http($this->user));
        runThrough(TracedNote::class, ['title' => 'Hi'], ActionContext::http($this->user));

        expect(TracedNote::$instances)->toBe(2);
    });
});

describe('Translate (step 4b)', function () {
    beforeEach(fn () => $this->skipUnlessAi());

    it('validates the agent schema, maps it and validates again', function () {
        $outcome = runThrough(TranslatedNote::class, ['headline' => 'Hello', 'title' => 'ignored'], ActionContext::agent($this->user), Door::Agent, ['default']);

        expect($outcome->kind())->toBe(OutcomeKind::Ok)
            ->and($outcome->output())->toMatchArray(['title' => 'Hello'])
            ->and(Post::query()->where('title', 'Hello')->where('body', 'From an agent.')->exists())->toBeTrue();
    });

    it('rejects invalid agent input against the agent schema', function () {
        $outcome = runThrough(TranslatedNote::class, ['title' => 'Hi', 'body' => 'x'], ActionContext::agent($this->user), Door::Agent, ['default']);

        expect($outcome->kind())->toBe(OutcomeKind::Invalid)
            ->and($outcome->failedRules())->toBe(['headline' => ['required']]);
    });

    it('turns a refusal from fromAgent() into a listing a model reads as data', function () {
        $outcome = runThrough(TranslatedNote::class, ['headline' => 'refuse'], ActionContext::agent($this->user), Door::Agent, ['default']);

        expect($outcome->kind())->toBe(OutcomeKind::Refused)
            ->and($outcome->forModel())->toBe("No headline like that.\n---\n[\"First\",\"Second\"]");
    });

    it('reads a missing row in fromAgent() as not found', function () {
        expect(runThrough(TranslatedNote::class, ['headline' => 'missing'], ActionContext::agent($this->user), Door::Agent, ['default'])->kind())
            ->toBe(OutcomeKind::NotFound);
    });

    it('reports a canonical failure on a key the agent was never offered as a failure', function () {
        Exceptions::fake();

        $outcome = runThrough(TranslatedNote::class, ['headline' => 'too long for a title'], ActionContext::agent($this->user), Door::Agent, ['default']);

        expect($outcome->kind())->toBe(OutcomeKind::Failed)
            ->and($outcome->exception()?->getMessage())->toContain(TranslatedNote::class)->toContain('[title]')
            ->and($outcome->forModel())->toBe(trans('agentic-actions::model.failed'));

        Exceptions::assertReportedCount(1);
    });

    it('never translates on the in-process door', function () {
        expect(runThrough(TranslatedNote::class, ['title' => 'Canonical', 'body' => 'x'], ActionContext::agent($this->user))->kind())->toBe(OutcomeKind::Ok);
    });

    it('prunes the arguments to what the agent was offered', function () {
        $outcome = runThrough(CreateNote::class, ['title' => 'Hi', 'body' => 'x', 'user_id' => 999, 'status' => 'published'], ActionContext::agent($this->user), Door::Agent, ['default']);

        expect($outcome->kind())->toBe(OutcomeKind::Ok)
            ->and($outcome->result()->user_id)->toBe($this->user->getKey())
            ->and($outcome->result()->status)->toBe('draft');
    });
});

describe('Action::run()', function () {
    it('returns handle()\'s result', function () {
        $post = CreateNote::run(['title' => 'Hi', 'body' => 'x'], ActionContext::http($this->user));

        expect($post)->toBeInstanceOf(Post::class)
            ->and($post->title)->toBe('Hi');
    });

    it('throws a ValidationException in the entry\'s error bag', function () {
        try {
            BaggedNote::run([], ActionContext::http($this->user));
            $this->fail('No exception.');
        } catch (ValidationException $exception) {
            expect($exception->errorBag)->toBe('notes')
                ->and($exception->errors())->toHaveKey('title');
        }
    });

    it('throws a 404 refusal for an action this context cannot reach', function () {
        try {
            HiddenNote::run([], ActionContext::http($this->user));
            $this->fail('No refusal.');
        } catch (Refusal $refusal) {
            expect($refusal->statusCode())->toBe(404)
                ->and($refusal->key())->toBe('agentic-actions::http.not_found');
        }
    });

    it('throws the action\'s own refusal, and a 403 for a denial', function () {
        expect(fn () => RefusingNote::run([], ActionContext::http($this->user)))->toThrow(Refusal::class, 'This note cannot be saved.')
            ->and(fn () => NoAuthorize::run([], ActionContext::http($this->user)))->toThrow(fn (Refusal $refusal) => expect($refusal->statusCode())->toBe(403));
    });

    it('rethrows a crash unreported, after ActionFailed', function () {
        Exceptions::fake();
        Event::fake([ActionFailed::class]);

        expect(fn () => CrashingNote::run([], ActionContext::http($this->user)))->toThrow(RuntimeException::class, 'The note crashed.');

        Exceptions::assertNothingReported();
        Event::assertDispatched(ActionFailed::class, fn (ActionFailed $event): bool => $event->exceptionClass === RuntimeException::class);
    });
});

describe('Actions::attempt()', function () {
    it('returns the outcome of any action class, discovered or not', function () {
        $outcome = app(ActionsManager::class)->attempt(BaggedNote::class, ['title' => 'Hi'], ActionContext::http($this->user));

        expect($outcome->kind())->toBe(OutcomeKind::Ok)
            ->and($outcome->result())->toBe('Hi');
    });

    it('reports a crash once and returns Failed', function () {
        Exceptions::fake();

        $outcome = app(ActionsManager::class)->attempt(CrashingNote::class, [], ActionContext::http($this->user));

        expect($outcome->kind())->toBe(OutcomeKind::Failed)
            ->and($outcome->exception())->toBeInstanceOf(RuntimeException::class);

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(RuntimeException::class);
    });
});

describe('events (step 10)', function () {
    it('fires ActionCompleted with no input values', function () {
        Event::fake([ActionCompleted::class]);

        CreateNote::run(['title' => 'Secret title', 'body' => 'x'], ActionContext::http($this->user));

        Event::assertDispatched(ActionCompleted::class, function (ActionCompleted $event): bool {
            expect($event->action)->toBe('create-note')
                ->and($event->class)->toBe(CreateNote::class)
                ->and($event->surface->value)->toBe('http')
                ->and($event->effect)->toBe(Effect::Write)
                ->and($event->modelDriven)->toBeFalse()
                ->and($event->actorType)->toBe($this->user->getMorphClass())
                ->and($event->actorId)->toBe($this->user->getKey())
                ->and($event->tenantId)->toBeNull()
                ->and($event->requestId)->not->toBe('')
                ->and($event->durationMs)->toBeGreaterThanOrEqual(0.0)
                ->and(serialize($event))->not->toContain('Secret title');

            return true;
        });
    });

    it('fires ActionRefused with the reason, the status and the failed rules', function () {
        Event::fake([ActionRefused::class]);

        app(ActionsManager::class)->attempt(TracedNote::class, ['title' => str_repeat('x', 30)], ActionContext::agent($this->user));
        app(ActionsManager::class)->attempt(HiddenNote::class, [], ActionContext::http($this->user));

        Event::assertDispatched(ActionRefused::class, fn (ActionRefused $event): bool => $event->reason === 'invalid'
            && $event->status === 422
            && $event->failedRules === ['title' => ['max']]
            && $event->modelDriven
            && ! str_contains(serialize($event), str_repeat('x', 30)));

        Event::assertDispatched(ActionRefused::class, fn (ActionRefused $event): bool => $event->reason === 'not_found' && $event->status === 404);
    });

    it('fires ActionFailed with the exception class only', function () {
        Exceptions::fake();
        Event::fake([ActionFailed::class]);

        app(ActionsManager::class)->attempt(CrashingNote::class, [], ActionContext::http($this->user));

        Event::assertDispatched(ActionFailed::class, fn (ActionFailed $event): bool => $event->exceptionClass === RuntimeException::class
            && ! str_contains(serialize($event), 'The note crashed.'));
    });

    it('fires after the surrounding transaction commits, and not at all when it rolls back', function () {
        $levels = [];

        Event::listen(ActionCompleted::class, function () use (&$levels): void {
            $levels[] = DB::transactionLevel();
        });

        $inside = null;

        DB::transaction(function () use (&$inside): void {
            $inside = DB::transactionLevel();

            TracedNote::run(['title' => 'Hi'], ActionContext::http($this->user));
        });

        expect($levels)->toBe([$inside - 1]);

        try {
            DB::transaction(function (): void {
                TracedNote::run(['title' => 'Hi'], ActionContext::http($this->user));

                throw new RuntimeException('Roll back.');
            });
        } catch (RuntimeException) {
            //
        }

        expect($levels)->toBe([$inside - 1]);
    });
});

it('lists an action for a model surface only when steps 1 to 3 pass, firing nothing', function () {
    $this->skipUnlessAi();
    Event::fake();

    $runner = app(Runner::class);
    $context = ActionContext::agent($this->user);

    expect($runner->exposed(ClassExposure::of(CreateNote::class), $context, Door::Agent, ['default']))->toBeTrue()
        ->and($runner->exposed(ClassExposure::of(CreateNote::class), ActionContext::agent(null), Door::Agent, ['default']))->toBeFalse()
        ->and($runner->exposed(ClassExposure::of(CreateNote::class), $context, Door::Agent, ['support']))->toBeFalse()
        ->and($runner->exposed(ClassExposure::of(HiddenNote::class), $context, Door::Agent, ['default']))->toBeFalse();

    Event::assertNothingDispatched();
});

it('reports a crash in an input-free authorize() while listing, and reads it as not listed', function () {
    Exceptions::fake();
    TracedNote::$authorizes = fn () => throw new RuntimeException('Broken policy.');

    expect(app(Runner::class)->exposed(ClassExposure::of(TracedNote::class), ActionContext::http($this->user), Door::InProcess))->toBeFalse();

    Exceptions::assertReported(RuntimeException::class);
});

it('treats an entry whose class is gone as not found', function () {
    $entry = Entry::fromManifest([...ClassExposure::of(PlainNote::class)->toManifest(), 'class' => 'App\\Actions\\Deleted']);

    expect(app(Runner::class)->run($entry, [], ActionContext::http($this->user), Door::InProcess)->kind())->toBe(OutcomeKind::NotFound);
});

/**
 * An action with its own error bag.
 */
final class BaggedNote extends Action
{
    protected ?Effect $effect = Effect::Write;

    protected string $errorBag = 'notes';

    public function schema(JsonSchema $schema): array
    {
        return ['title' => $schema->string()->required()];
    }

    public function authorize(ActionContext $context): bool
    {
        return true;
    }

    public function handle(ValidatedInput $input): string
    {
        return $input->string('title')->toString();
    }
}

/**
 * Takes one number and hands it back.
 */
final class CountNote extends Action
{
    protected ?Effect $effect = Effect::Read;

    public function schema(JsonSchema $schema): array
    {
        return ['count' => $schema->number()->required()];
    }

    public function authorize(ActionContext $context): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(ValidatedInput $input): array
    {
        return $input->all();
    }
}

/**
 * Takes lists of objects whose keys are all optional, and a map rules() owns, and hands them back.
 */
final class ItemsNote extends Action
{
    protected ?Effect $effect = Effect::Read;

    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items($schema->object(['note' => $schema->string()]))->required(),
            'maybe' => $schema->array()->items($schema->object(['note' => $schema->string(), 'tags' => $schema->array()->items($schema->string())])->nullable()),
        ];
    }

    public function rules(ActionContext $context): array
    {
        return ['scores' => ['sometimes', 'array']];
    }

    public function authorize(ActionContext $context): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(ValidatedInput $input): array
    {
        return $input->all();
    }
}
