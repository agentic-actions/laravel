<?php

use AgenticActions\Streaming\ActionsProtocol;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Tests\Fixtures\Actions\Trace;
use Tests\Fixtures\Streaming\ErrorGateway;
use Tests\Fixtures\Streaming\LinkedNote;
use Tests\Fixtures\Streaming\OneStepGateway;
use Tests\Fixtures\Streaming\Parts;
use Tests\Fixtures\Streaming\SaveNote;
use Tests\Fixtures\Streaming\StreamAgent;
use Tests\Fixtures\Streaming\ThrowingTool;
use Tests\Fixtures\Streaming\TracedTool;
use Workbench\App\Models\User;

/*
 * The wire: an allowlist of part types and keys, rows between the step boundaries, host parts before finish, and one
 * fixed error sentence last on every failure path.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config([
        'agentic-actions.discovery.paths' => [...config('agentic-actions.discovery.paths'), dirname(__DIR__, 2).'/Fixtures/Streaming'],
        'ai.conversations.generate_title' => false,
    ]);

    $this->refreshActions();

    Auth::shouldUse('web');
    Trace::reset();

    SaveNote::$throwingLabel = false;
    LinkedNote::$redirect = null;
    TracedTool::$mode = 'ok';
    ThrowingTool::$validation = false;

    $this->user = User::factory()->create();

    $this->canaries = ['CANARY-ARG', 'CANARY-OUT', 'CANARY-REFUSAL', 'CANARY-EXCEPTION', 'CANARY-REASONING', 'CANARY-SOURCE', 'CANARY-PROVIDER', 'CANARY-MODEL', '424242', '434343'];

    // A turn with every kind of text a browser must never see: an argument, an action's output, a refusal, a crash,
    // reasoning, a citation, usage and the model's name.
    $this->successTurn = function (?Closure $after = null): string {
        $this->user->posts()->create(['title' => 'CANARY-OUT', 'body' => 'x', 'status' => 'draft']);

        (new OneStepGateway(
            [
                new ToolCall('call_1', 'save-note', ['title' => 'CANARY-ARG']),
                new ToolCall('call_2', 'read-notes', []),
                new ToolCall('call_3', 'outcome-note', ['mode' => 'refuse']),
                new ToolCall('call_4', 'outcome-note', ['mode' => 'crash']),
                new ToolCall('call_5', 'TracedTool', []),
            ],
            'All done.',
            new TextUsage(424242, 434343),
            'CANARY-REASONING about it',
            [new UrlCitation('https://source.example/CANARY-SOURCE', 'Source')],
        ))->fake(StreamAgent::class);

        return Parts::body((new StreamAgent($this->user))->stream('Save CANARY-ARG.', model: 'CANARY-MODEL'), new ActionsProtocol($after));
    };
});

afterEach(function () {
    ignore_user_abort(false);
});

/**
 * The keys each part type may carry, exactly.
 *
 * @param  array<string, mixed>  $part
 */
function allowlistedKeys(array $part): array
{
    return match ($part['type']) {
        'start' => array_key_exists('messageId', $part) ? ['type', 'messageId'] : ['type'],
        'start-step', 'finish-step' => ['type'],
        'text-start', 'text-end' => ['type', 'id'],
        'text-delta' => ['type', 'id', 'delta'],
        'finish' => array_key_exists('finishReason', $part) ? ['type', 'finishReason'] : ['type'],
        'error' => ['type', 'errorText'],
        default => str_starts_with($part['type'], 'data-')
            ? (array_key_exists('id', $part) ? ['type', 'id', 'data'] : ['type', 'data'])
            : [],
    };
}

describe('the allowlist', function () {
    it('gives each part exactly its allowlisted keys, and drops every tool, reasoning, source and custom part', function () {
        Exceptions::fake();

        $parts = Parts::of(($this->successTurn)(fn (): array => [['type' => 'data-notes', 'data' => ['count' => 1]]]));

        foreach ($parts as $part) {
            if ($part === '[DONE]') {
                continue;
            }

            expect(array_keys($part))->toBe(allowlistedKeys($part))
                ->and($part['type'])->not->toStartWith('tool-')
                ->not->toStartWith('reasoning-')
                ->not->toStartWith('source-')
                ->not->toBe('custom');

            if ($part['type'] === 'data-action') {
                expect(array_diff(array_keys($part['data']), ['action', 'label', 'status', 'effect', 'note', 'touches', 'link']))->toBe([]);
            }
        }

        expect(Parts::types($parts))->toContain('data-action', 'text-delta', 'data-notes', 'finish');
    });

    it('never lets an argument, an output, a refusal, an exception, reasoning, a source, a provider error, the model or usage through', function (Closure $turn) {
        Exceptions::fake();

        $body = $turn->call($this);

        expect($body)->toEndWith("data: [DONE]\n\n")->not->toContain('messageMetadata');

        foreach ($this->canaries as $canary) {
            expect($body)->not->toContain($canary);
        }
    })->with([
        'a success with every kind of text' => [fn (): string => ($this->successTurn)()],
        'a provider error with no step response' => [function (): string {
            (new ErrorGateway([new ToolCall('call_1', 'save-note', ['title' => 'CANARY-ARG'])]))->fake(StreamAgent::class);

            return Parts::body((new StreamAgent($this->user))->stream('Go.', model: 'CANARY-MODEL'));
        }],
        'a provider error followed by a step response' => [function (): string {
            (new ErrorGateway(respond: true))->fake(StreamAgent::class);

            return Parts::body((new StreamAgent($this->user))->stream('Go.', model: 'CANARY-MODEL'));
        }],
        'an exception out of the stream' => [function (): string {
            (new OneStepGateway([new ToolCall('call_1', 'ThrowingTool', [])]))->fake(StreamAgent::class);

            return Parts::body((new StreamAgent($this->user))->stream('Go.', model: 'CANARY-MODEL'));
        }],
    ]);

    it('drops a malformed part and never throws while redacting', function () {
        $redact = fn (array $part): ?array => (fn (): ?array => $this->redact($part))->call(new ActionsProtocol);

        expect($redact(['type' => 'text-delta', 'delta' => 'x']))->toBeNull()
            ->and($redact(['type' => 'text-delta', 'id' => 't1', 'delta' => ['x']]))->toBeNull()
            ->and($redact(['type' => 'text-start', 'id' => 7]))->toBeNull()
            ->and($redact(['delta' => 'no type']))->toBeNull()
            ->and($redact(['type' => 'start', 'messageId' => ['x']]))->toBe(['type' => 'start'])
            ->and($redact(['type' => 'finish', 'finishReason' => 'stop', 'messageMetadata' => ['usage' => 1]]))->toBe(['type' => 'finish', 'finishReason' => 'stop'])
            ->and($redact(['type' => 'text-delta', 'id' => 't1', 'delta' => 'ok', 'providerMetadata' => []]))->toBe(['type' => 'text-delta', 'id' => 't1', 'delta' => 'ok']);
    });
});

describe('the order', function () {
    it('puts rows inside their step, and host parts before the last finish-step and finish', function () {
        Exceptions::fake();

        $failed = null;
        $parts = Parts::of(($this->successTurn)(function (StreamableAgentResponse $response, bool $turnFailed) use (&$failed): array {
            $failed = $turnFailed;

            return [['type' => 'data-notes', 'id' => 'n1', 'data' => ['count' => 1]]];
        }));

        expect(Parts::types($parts))->toBe([
            'start', 'start-step',
            ...array_fill(0, 10, 'data-action'),
            'finish-step', 'start-step',
            'text-start', 'text-delta', 'text-delta', 'text-end',
            'data-notes',
            'finish-step', 'finish',
            '[DONE]',
        ])->and($failed)->toBeFalse()
            ->and($parts[array_search('finish', Parts::types($parts), true)])->toBe(['type' => 'finish', 'finishReason' => 'stop']);
    });
});

describe('failures', function () {
    it('closes open rows as ended, sends the host\'s failure parts and one error last after an in-stream provider error', function (bool $respond) {
        Exceptions::fake();
        TracedTool::$mode = 'nothing';

        // A row whose close never arrives stays open until the stream ends.
        Event::forget(ToolInvoked::class);

        (new ErrorGateway([new ToolCall('call_1', 'TracedTool', [])], respond: $respond))->fake(StreamAgent::class);

        $failed = null;
        $parts = Parts::of(Parts::body((new StreamAgent($this->user))->stream('Go.'), new ActionsProtocol(function (StreamableAgentResponse $response, bool $turnFailed) use (&$failed): array {
            $failed = $turnFailed;

            return [['type' => 'data-notes', 'data' => ['failed' => $turnFailed]]];
        })));

        $types = Parts::types($parts);

        expect(array_slice($types, -4))->toBe(['data-action', 'data-notes', 'error', '[DONE]'])
            ->and($types)->not->toContain('finish')
            ->and(array_count_values($types)['error'])->toBe(1)
            ->and(Parts::rows($parts))->toBe([['action' => 'TracedTool', 'label' => 'Tracing…', 'status' => 'ended']])
            ->and($parts[count($parts) - 2])->toBe(['type' => 'error', 'errorText' => 'The reply stopped before it finished. Anything already saved stays saved.'])
            ->and($failed)->toBeTrue();

        if ($respond) {
            Exceptions::assertNothingReported();
        } else {
            Exceptions::assertReported(StreamErrorException::class);
            Exceptions::assertReportedCount(1);
        }
    })->with([
        'with no step response' => [false],
        'followed by a step response' => [true],
    ]);

    it('sends the failed row, the host\'s parts and one error when an exception leaves the stream, and reports it once', function () {
        Exceptions::fake();

        (new OneStepGateway([new ToolCall('call_1', 'ThrowingTool', [])]))->fake(StreamAgent::class);

        $parts = Parts::of(Parts::body((new StreamAgent($this->user))->stream('Go.'), new ActionsProtocol(fn (StreamableAgentResponse $response, bool $failed): array => [['type' => 'data-notes', 'data' => ['failed' => $failed]]])));

        expect(Parts::types($parts))->toBe(['start', 'start-step', 'data-action', 'data-action', 'data-notes', 'error', '[DONE]'])
            ->and(Parts::rows($parts))->toBe([['action' => 'ThrowingTool', 'label' => 'Checking…', 'status' => 'failed', 'note' => 'Couldn\'t finish']])
            ->and($parts[4]['data'])->toBe(['failed' => true]);

        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'CANARY-EXCEPTION');
        Exceptions::assertReportedCount(1);
    });

    it('sends start, start-step and the error when the stream fails before any part', function () {
        Exceptions::fake();

        (new ErrorGateway(started: false))->fake(StreamAgent::class);

        $parts = Parts::of(Parts::body((new StreamAgent($this->user))->stream('Go.')));

        expect(Parts::types($parts))->toBe(['start', 'start-step', 'error', '[DONE]']);
    });

    it('words the error in the request\'s locale, and lets an app\'s own line win', function () {
        Exceptions::fake();

        app()->setLocale('ar');
        (new ErrorGateway(started: false))->fake(StreamAgent::class);

        $arabic = Parts::of(Parts::body((new StreamAgent($this->user))->stream('Go.')));

        app()->setLocale('en');
        app('translator')->addLines(['stream.interrupted' => 'Our own words.'], 'en', 'agentic-actions');
        (new ErrorGateway(started: false))->fake(StreamAgent::class);

        $overridden = Parts::of(Parts::body((new StreamAgent($this->user))->stream('Go.')));

        expect($arabic[2])->toBe(['type' => 'error', 'errorText' => 'توقّف الرد قبل أن يكتمل. ما حُفظ يبقى محفوظًا.'])
            ->and($overridden[2])->toBe(['type' => 'error', 'errorText' => 'Our own words.']);
    });
});

describe('host parts', function () {
    it('reports and drops a part that is not a data-* part the host may send, and narrows a good one to type, id and data', function () {
        Exceptions::fake();

        (new OneStepGateway)->fake(StreamAgent::class);

        $parts = Parts::of(Parts::body((new StreamAgent($this->user))->stream('Go.'), new ActionsProtocol(fn (): array => [
            ['type' => 'text-delta', 'id' => 't1', 'delta' => 'x', 'data' => 1],
            ['type' => 'data-action', 'id' => 'a:1', 'data' => []],
            ['type' => 'data-approval', 'data' => []],
            ['type' => 'data-elicitation', 'id' => 'elicitation:call_1', 'data' => ['params' => ['message' => 'x']]],
            ['type' => 'data-notes'],
            ['type' => 'data-notes', 'id' => 7, 'data' => []],
            'data-notes',
            ['type' => 'data-notes', 'id' => 'n1', 'data' => ['count' => 2], 'transient' => true, 'providerMetadata' => ['x' => 1]],
        ])));

        $host = array_values(array_filter($parts, fn (array|string $part): bool => is_array($part) && str_starts_with($part['type'], 'data-')));

        expect($host)->toBe([['type' => 'data-notes', 'id' => 'n1', 'data' => ['count' => 2]]])
            ->and(Parts::types($parts))->toContain('finish');

        Exceptions::assertReportedCount(7);
        Exceptions::assertReported(fn (LogicException $exception): bool => $exception->getMessage() === 'A host part must be a data-* part other than data-action, data-approval, data-elicitation or data-view, with a data key and an optional string id; one was dropped.');
    });

    it('reports a throwing $after and still ends the stream', function () {
        Exceptions::fake();

        (new OneStepGateway)->fake(StreamAgent::class);

        $parts = Parts::of(Parts::body((new StreamAgent($this->user))->stream('Go.'), new ActionsProtocol(fn (): never => throw new RuntimeException('The host failed.'))));

        expect(array_slice(Parts::types($parts), -3))->toBe(['finish-step', 'finish', '[DONE]']);

        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'The host failed.');
    });
});

describe('the transport', function () {
    it('sends X-Accel-Buffering: no beside laravel/ai\'s headers', function () {
        $headers = (fn (): array => $this->headers())->call(new ActionsProtocol);

        expect($headers)->toBe([
            'Cache-Control' => 'no-cache, no-transform',
            'Content-Type' => 'text/event-stream',
            'x-vercel-ai-ui-message-stream' => 'v1',
            'X-Accel-Buffering' => 'no',
        ]);
    });

    it('runs the turn and sends every part under Octane, whose FrankenPHP and Swoole clients only call send()', function () {
        $_SERVER['LARAVEL_OCTANE'] = 1;   // the framework then hands the server the generator itself

        try {
            (new OneStepGateway([new ToolCall('call_1', 'TracedTool', [])]))->fake(StreamAgent::class);

            $response = (new StreamAgent($this->user))->stream('Go.')->usingProtocol(new ActionsProtocol)->toResponse(request());

            // Swoole's client reads the body this way; each ob_flush() hands the part over.
            $body = '';
            ob_start(function (string $chunk) use (&$body): string {
                $body .= $chunk;

                return '';
            }, 1);
            $response->send();
            ob_end_clean();
        } finally {
            unset($_SERVER['LARAVEL_OCTANE']);
        }

        expect(Trace::$calls)->toBe(['TracedTool ignore_user_abort=1'])
            ->and($body)->not->toBe('')
            ->and(Parts::rows(Parts::of($body)))->toHaveCount(1)
            ->and(array_slice(Parts::types(Parts::of($body)), -3))->toBe(['finish-step', 'finish', '[DONE]']);
    });

    it('ignores a closed tab while the tools run', function () {
        ignore_user_abort(false);

        (new OneStepGateway([new ToolCall('call_1', 'TracedTool', [])]))->fake(StreamAgent::class);

        Parts::body((new StreamAgent($this->user))->stream('Go.'));

        expect(Trace::$calls)->toBe(['TracedTool ignore_user_abort=1']);
    });
});

describe('the current protocol', function () {
    it('is null after the stream, and the outer protocol again after an inner one', function () {
        $outer = new ActionsProtocol;
        $inner = new ActionsProtocol;
        $seen = [];

        $innerResponse = new StreamableAgentResponse('inner-run', function () use ($inner, &$seen): Generator {
            $seen['inside inner'] = ActionsProtocol::current('inner-run') === $inner && ActionsProtocol::current('outer-run') === null;

            yield from [];
        }, new Meta);

        $outerResponse = new StreamableAgentResponse('outer-run', function () use ($outer, $inner, $innerResponse, &$seen): Generator {
            $seen['inside outer'] = ActionsProtocol::current('outer-run') === $outer && ActionsProtocol::current() === $outer;

            iterator_to_array((fn (): Generator => $this->parts($innerResponse))->call($inner), false);

            $seen['after inner'] = ActionsProtocol::current('outer-run') === $outer;

            yield from [];
        }, new Meta);

        iterator_to_array((fn (): Generator => $this->parts($outerResponse))->call($outer), false);

        expect($seen)->toBe(['inside outer' => true, 'inside inner' => true, 'after inner' => true])
            ->and(ActionsProtocol::current())->toBeNull();
    });
});
