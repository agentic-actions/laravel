<?php

use AgenticActions\ActionContext;
use AgenticActions\Ai\ActionTool;
use AgenticActions\Contracts\DescribesActivity;
use AgenticActions\Exceptions\MisconfiguredExposure;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Streaming\ActionsProtocol;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\ListNotes;
use Tests\Fixtures\Actions\TranslatedNote;
use Tests\Fixtures\Ai\RulesOnlyTeamKey;
use Tests\Fixtures\Misconfigured\UndeclaredEffect;
use Tests\Fixtures\Streaming\SaveNote;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * The tool laravel/ai sees: the action's name, description and advertised schema, and a handle() that never throws.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config(['agentic-actions.discovery.paths' => [
        ...config('agentic-actions.discovery.paths'),
        dirname(__DIR__, 2).'/Fixtures/Ai',
    ]]);

    $this->refreshActions();

    RulesOnlyTeamKey::reset();
    Auth::shouldUse('web');

    $this->user = User::factory()->create();
    $this->tool = fn (string $class, ?ActionContext $context = null): ActionTool => new ActionTool(
        ClassExposure::of($class),
        $context ?? ActionContext::agent($this->user),
        ['default'],
    );
});

it('advertises agentSchema() over schema()', function () {
    $types = ($this->tool)(TranslatedNote::class)->schema(new JsonSchemaTypeFactory);

    expect(array_keys($types))->toBe(['headline']);
});

it('returns the model sentence for the outcome', function () {
    $tool = ($this->tool)(CreateNote::class);

    expect($tool->handle(new Request(['title' => 'Hi', 'body' => 'x'], 'call_1', 'inv_1')))->toBe('Done.')
        ->and($tool->handle(new Request(['title' => str_repeat('x', 30), 'body' => 'x'], 'call_2', 'inv_2')))->toBe('Not done. Rejected: title (max).')
        ->and(($this->tool)(CreateNote::class, ActionContext::agent(null))->handle(new Request(['title' => 'Hi', 'body' => 'x'])))
        ->toBe('Not done: that action is not available here. Do not try it again.')
        ->and(Post::query()->pluck('title')->all())->toBe(['Hi']);
});

it('never throws when the door throws, and reports the throwable once', function () {
    Exceptions::fake();

    $tool = ($this->tool)(CreateNote::class);

    config(['agentic-actions.discovery.classes' => [UndeclaredEffect::class]]);
    $this->refreshActions();

    expect($tool->handle(new Request(['title' => 'Hi', 'body' => 'x'], 'call_1')))
        ->toBe('Not done: something went wrong on the server. Tell the person it did not finish.')
        ->and(Post::query()->count())->toBe(0);

    Exceptions::assertReported(MisconfiguredExposure::class);
    Exceptions::assertReportedCount(1);
});

it('still answers when reporting the throwable fails too', function () {
    $reported = 0;

    $this->app->make(ExceptionHandler::class)->reportable(function (MisconfiguredExposure $exception) use (&$reported): never {
        $reported++;

        throw new RuntimeException('The reporter failed.');
    });

    $tool = ($this->tool)(CreateNote::class);

    config(['agentic-actions.discovery.classes' => [UndeclaredEffect::class]]);
    $this->refreshActions();

    expect($tool->handle(new Request(['title' => 'Hi', 'body' => 'x'], 'call_1')))
        ->toBe('Not done: something went wrong on the server. Tell the person it did not finish.')
        ->and($reported)->toBe(1);
});

it('keys the call by the tool-call id, ignoring a blank one, unless the host set a key', function (?string $callId, ?string $hostKey, ?string $key) {
    $context = $hostKey === null ? ActionContext::agent($this->user) : ActionContext::agent($this->user)->withIdempotencyKey($hostKey);

    ($this->tool)(RulesOnlyTeamKey::class, $context)->handle(new Request(['title' => 'Hi'], $callId, 'inv_1'));

    expect(RulesOnlyTeamKey::$context?->idempotencyKey)->toBe($key);
})->with([
    'the tool-call id' => ['call_1', null, 'call_1'],
    'no id' => [null, null, null],
    'an empty id' => ['', null, null],
    'an id of spaces' => ['   ', null, null],
    'a host key over the id' => ['call_1', 'host-key', 'host-key'],
]);

describe('the copilot row', function () {
    beforeEach(function () {
        SaveNote::$throwingLabel = false;

        // Run the tool's handle() while a stream holds an open row for its invocation, and return the row it closes to.
        $this->rowAfter = function (ActionTool $tool, array $arguments) {
            $protocol = new ActionsProtocol;
            $seen = [];

            $response = new StreamableAgentResponse('run-1', function () use ($protocol, $tool, $arguments, &$seen): Generator {
                $protocol->start('inv_1', $tool->name(), 'Saving…', 'Saved', $tool->entry()->effect);

                $seen['answer'] = $tool->handle(new Request($arguments, 'call_1', 'inv_1'));
                $seen['row'] = $protocol->finish('inv_1', threw: false)[0]['data'] ?? null;

                yield from [];
            }, new Meta);

            iterator_to_array((fn (): Generator => $this->parts($response))->call($protocol), false);

            return $seen;
        };
    });

    afterEach(function () {
        ignore_user_abort(false);
    });

    it('describes its activity with the package\'s labels for the action\'s effect, in the context\'s locale', function () {
        app()->setLocale('en');

        $write = ($this->tool)(CreateNote::class);
        $read = ($this->tool)(ListNotes::class, ActionContext::agent($this->user, locale: 'ar'));

        expect($write)->toBeInstanceOf(DescribesActivity::class)
            ->and([$write->activityLabel(false), $write->activityLabel(true)])->toBe(['Saving…', 'Saved'])
            ->and([$read->activityLabel(false), $read->activityLabel(true)])->toBe(['جارٍ البحث…', 'انتهى البحث'])
            ->and(app()->getLocale())->toBe('en');
    });

    it('describes its activity with the action\'s own labels', function () {
        $tool = ($this->tool)(SaveNote::class);

        expect([$tool->activityLabel(false), $tool->activityLabel(true)])->toBe(['Saving your note…', 'Note saved']);
    });

    it('records failed for a throwable, and still answers model.failed', function () {
        Exceptions::fake();

        $tool = ($this->tool)(CreateNote::class);

        config(['agentic-actions.discovery.classes' => [UndeclaredEffect::class]]);
        $this->refreshActions();

        expect(($this->rowAfter)($tool, ['title' => 'Hi', 'body' => 'x']))->toBe([
            'answer' => 'Not done: something went wrong on the server. Tell the person it did not finish.',
            'row' => ['action' => 'create-note', 'label' => 'Saving…', 'status' => 'failed', 'effect' => 'write', 'note' => 'Couldn\'t finish'],
        ]);

        Exceptions::assertReported(MisconfiguredExposure::class);
    });
});
