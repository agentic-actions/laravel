<?php

use AgenticActions\Elicitation\Form;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tests\Fixtures\Approvals\ScriptedGateway;
use Tests\Fixtures\Elicitation\AskingAgent;
use Tests\Fixtures\Elicitation\AskingDraft;
use Tests\Fixtures\Streaming\Parts;
use Workbench\App\Models\User;

/*
 * The wire of a form at the pause: a paused asking call crosses as 0.4's input-less tool part and its approval
 * request, then its data-elicitation part, and nothing of the model's arguments outside the form's accepted defaults
 * reaches any part. The part's literal is Seams05/FormTest's; the resume, and a host's forged part, are the workbench's
 * and ActionsProtocolTest's.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config([
        'app.name' => 'Blog',
        'agentic-actions.discovery.paths' => [dirname(__DIR__, 2).'/Fixtures/Elicitation'],
        'ai.conversations.generate_title' => false,
    ]);

    $this->refreshActions();
    Auth::shouldUse('web');
    AskingDraft::reset();

    $this->user = User::factory()->create();
    $this->arguments = ['title' => 'Launch notes', 'status' => 'CANARY-STATUS', 'mood' => 'CANARY-KEY'];

    // A turn in which the model writes words, then calls the asking action with too little: it pauses.
    $this->pause = function (): string {
        (new ScriptedGateway([['asking-draft', $this->arguments]], 'Saved it.', 'A few details first.'))->install();

        return Parts::body((new AskingAgent($this->user))->forUser($this->user)->stream('Draft a post.'));
    };
});

afterEach(function () {
    ignore_user_abort(false);
});

describe('the pause', function () {
    it('streams the input-less tool part and its approval request with no reason, then the form', function () {
        $body = ($this->pause)();
        $parts = Parts::of($body);
        $types = Parts::types($parts);

        expect(array_slice($types, array_search('tool-input-available', $types, true), 3))->toBe(['tool-input-available', 'tool-approval-request', 'data-elicitation'])
            ->and(collect($parts)->firstWhere('type', 'tool-input-available'))->toBe(['type' => 'tool-input-available', 'toolCallId' => 'call_1', 'toolName' => 'asking-draft', 'input' => []])
            ->and(collect($parts)->firstWhere('type', 'tool-approval-request'))->toBe(['type' => 'tool-approval-request', 'toolCallId' => 'call_1', 'approvalId' => 'call_1'])
            ->and($body)->toContain('"input":{}')
            ->not->toContain('"reason"')
            ->and(AskingDraft::$handled)->toBeNull();
    });

    it('sends nothing of the model\'s arguments beyond the defaults a form holds: no unadvertised key, no refused value', function () {
        $body = ($this->pause)();
        $status = collect(Parts::of($body))->firstWhere('type', 'data-elicitation')['data']['params']['requestedSchema']['properties']['status'];

        expect($body)->not->toContain('CANARY')
            ->not->toContain('mood')
            ->and($status)->not->toHaveKey('default');
    });
});
