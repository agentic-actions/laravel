<?php

use AgenticActions\Streaming\ActionsProtocol;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\ToolResult;
use Tests\Fixtures\Actions\Trace;
use Tests\Fixtures\Approvals\HostConfirmedTool;
use Tests\Fixtures\Approvals\HostToolAgent;
use Tests\Fixtures\Approvals\ScriptedGateway;
use Tests\Fixtures\Streaming\Parts;
use Tests\Fixtures\Streaming\StreamAgent;
use Workbench\App\Models\User;

/*
 * The wire of a confirmation: a paused call crosses as an input-less tool part and its approval request, with no
 * reason; a call a confirmation resumed is settled with no output, or declined with a row; nothing the model wrote
 * into a call's arguments, and no tool's own reason or result, ever reaches the browser.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config(['ai.conversations.generate_title' => false]);

    Auth::shouldUse('web');
    Trace::reset();

    $this->user = User::factory()->create();

    // A turn in which the model writes words, then calls the host's tool with a canary argument: it pauses.
    $this->pause = function (?ActionsProtocol $protocol = null): string {
        (new ScriptedGateway([[HostConfirmedTool::NAME, ['note' => 'CANARY-ARG']]], 'Sent it.', 'I will ask first.'))->install();

        return Parts::body((new HostToolAgent($this->user))->forUser($this->user)->stream('Send a note.'), $protocol);
    };

    // Answer the paused call of the user's last conversation, streamed through the given protocol.
    $this->resume = function (Decisions $decisions, ActionsProtocol $protocol): string {
        $agent = (new HostToolAgent($this->user))->continueLastConversation($this->user);

        return Parts::body($agent->stream($decisions), $protocol);
    };
});

afterEach(function () {
    ignore_user_abort(false);
});

/**
 * A stream of the given events for the protocol, as a resume yields them: results first, then the end.
 *
 * @param  list<ToolResult>  $results
 */
function protocolApprovalResults(array $results): StreamableAgentResponse
{
    return new StreamableAgentResponse('invocation-1', function () use ($results) {
        foreach ($results as $result) {
            yield $result->withInvocationId('invocation-1');
        }

        yield (new StreamEnd('end-1', 'stop', new TextUsage, time()))->withInvocationId('invocation-1');
    }, new Meta('openai', 'gpt'));
}

describe('the pause', function () {
    it('streams the words, then the input-less tool part and its approval request, then the finish parts last', function () {
        $body = ($this->pause)();
        $parts = Parts::of($body);
        $types = array_values(array_filter(Parts::types($parts), fn (string $type): bool => ! str_starts_with($type, 'data-')));

        expect($types)->toBe(['start', 'start-step', 'text-start', 'text-delta', 'text-delta', 'text-delta', 'text-delta', 'text-end', 'tool-input-available', 'tool-approval-request', 'finish-step', 'finish', '[DONE]']);

        $tool = collect($parts)->firstWhere('type', 'tool-input-available');
        $request = collect($parts)->firstWhere('type', 'tool-approval-request');

        expect($tool)->toBe(['type' => 'tool-input-available', 'toolCallId' => 'call_1', 'toolName' => HostConfirmedTool::NAME, 'input' => []])
            ->and($request)->toBe(['type' => 'tool-approval-request', 'toolCallId' => 'call_1', 'approvalId' => 'call_1'])
            ->and($body)->toContain('"input":{}')
            ->and(Trace::$calls)->toBe([]);
    });

    it('never sends the call\'s arguments or the tool\'s reason', function () {
        $body = ($this->pause)();

        expect($body)->not->toContain('CANARY-ARG')
            ->not->toContain('CANARY-REASON')
            ->not->toContain('"reason"')
            ->not->toContain('toolName":"'.HostConfirmedTool::NAME.'","input":{"');
    });

    it('sends no approval request whose tool part it cannot build', function () {
        $parts = (fn (): array => $this->toolParts(['type' => 'tool-approval-request', 'toolCallId' => 'call_9', 'approvalId' => 'call_9', 'reason' => 'CANARY-REASON']))->call(new ActionsProtocol);

        expect($parts)->toBe([]);
    });
});

describe('relay()', function () {
    it('writes a data-approval part and a data-action part, and drops anything else', function () {
        Exceptions::fake();

        $protocol = new ActionsProtocol;

        // relay() flushes its own buffer level, so the outer buffer is the one that keeps what it wrote.
        ob_start();
        ob_start();
        $protocol->relay(['type' => 'data-approval', 'id' => 'approval:call_1', 'data' => ['title' => 'Delete this post?']]);
        $protocol->relay(['type' => 'data-action', 'id' => 'a:call_1', 'data' => ['status' => 'refused']]);
        $protocol->relay(['type' => 'tool-input-available', 'toolCallId' => 'call_1', 'toolName' => 'x', 'input' => ['note' => 'CANARY-ARG']]);
        $written = ob_get_clean().ob_get_clean();

        expect(Parts::types(Parts::of($written)))->toBe(['start', 'start-step', 'data-approval', 'data-action'])
            ->and($written)->not->toContain('CANARY-ARG');

        Exceptions::assertReported(fn (LogicException $exception): bool => $exception->getMessage() === 'relay() writes data-action, data-approval, data-elicitation and data-view parts only; one was dropped.');
    });
});

describe('the resume', function () {
    it('continues the answered message and settles an approved call with no output', function () {
        ($this->pause)();

        $parts = Parts::of(($this->resume)(Decisions::from(['call_1' => Decision::approve()]), new ActionsProtocol(messageId: 'msg-a1')));

        expect($parts[0])->toBe(['type' => 'start', 'messageId' => 'msg-a1'])
            ->and($parts[1])->toBe(['type' => 'start-step'])
            ->and($parts[2])->toBe(['type' => 'tool-output-available', 'toolCallId' => 'call_1', 'output' => null])
            ->and(Parts::types($parts))->toContain('text-delta', 'finish')
            ->and(Trace::$calls)->toBe(['HostConfirmedTool::handle']);
    });

    it('settles a declined call as denied and writes a declined row, in the stream\'s locale', function (string $locale, string $label) {
        ($this->pause)();

        app()->setLocale($locale);

        $parts = Parts::of(($this->resume)(Decisions::from(['call_1' => Decision::reject('No.')]), new ActionsProtocol(messageId: 'msg-a1')));

        expect(array_slice($parts, 2, 2))->toBe([
            ['type' => 'tool-output-denied', 'toolCallId' => 'call_1'],
            ['type' => 'data-action', 'id' => 'a:call_1', 'data' => ['action' => HostConfirmedTool::NAME, 'label' => $label, 'status' => 'declined']],
        ])->and(Trace::$calls)->toBe([]);
    })->with([
        'English' => ['en', 'Declined'],
        'Arabic' => ['ar', 'تم الرفض'],
    ]);

    it('sends no settled part and no declined row without a message id', function (bool $approved) {
        ($this->pause)();

        $body = ($this->resume)(Decisions::from(['call_1' => $approved ? Decision::approve() : Decision::reject('No.')]), new ActionsProtocol);

        expect(collect(Parts::types(Parts::of($body)))->filter(fn (string $type): bool => str_starts_with($type, 'tool-'))->all())->toBe([])
            ->and(Parts::rows(Parts::of($body)))->toBe([]);
    })->with([
        'approved' => [true],
        'declined' => [false],
    ]);

    it('settles a failed call with the fixed sentence and never its error', function () {
        $body = Parts::body(protocolApprovalResults([
            new ToolResult('r1', new ToolResultData('call_9', HostConfirmedTool::NAME, ['note' => 'CANARY-ARG'], 'The tool call failed: CANARY-EXCEPTION', failed: true), false, 'The tool call failed: CANARY-EXCEPTION', time()),
        ]), new ActionsProtocol(messageId: 'msg-a1'));

        expect(Parts::of($body)[2])->toBe(['type' => 'tool-output-error', 'toolCallId' => 'call_9', 'errorText' => (string) trans('agentic-actions::activity.failed')])
            ->and($body)->not->toContain('CANARY');
    });

    it('drops a preliminary result, and sends no output and no tool name', function () {
        $body = Parts::body(protocolApprovalResults([
            new ToolResult('r1', new ToolResultData('call_9', HostConfirmedTool::NAME, ['note' => 'CANARY-ARG'], 'CANARY-PARTIAL'), true, null, time(), preliminary: true),
            new ToolResult('r2', new ToolResultData('call_9', HostConfirmedTool::NAME, ['note' => 'CANARY-ARG'], 'CANARY-OUT'), true, null, time()),
        ]), new ActionsProtocol(messageId: 'msg-a1'));

        expect(collect(Parts::of($body))->filter(fn (array|string $part): bool => is_array($part) && str_starts_with($part['type'], 'tool-'))->values()->all())
            ->toBe([['type' => 'tool-output-available', 'toolCallId' => 'call_9', 'output' => null]])
            ->and($body)->not->toContain('CANARY')
            ->not->toContain('toolName');
    });

    it('sends no settled part for a call this stream started', function () {
        (new ScriptedGateway([['TracedTool', []]]))->install();

        $body = Parts::body((new StreamAgent($this->user))->stream('Trace it.'), new ActionsProtocol(messageId: 'msg-a1'));

        expect(collect(Parts::types(Parts::of($body)))->filter(fn (string $type): bool => str_starts_with($type, 'tool-'))->all())->toBe([])
            ->and(Parts::of($body)[0])->toBe(['type' => 'start', 'messageId' => 'msg-a1']);
    });
});
