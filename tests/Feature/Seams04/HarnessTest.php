<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\ToolApprovalRequested;
use Tests\Fixtures\Actions\Trace;
use Tests\Fixtures\Approvals\HostConfirmedTool;
use Tests\Fixtures\Approvals\HostToolAgent;
use Tests\Fixtures\Approvals\ScriptedGateway;
use Workbench\App\Models\User;

/*
 * The approvals harness the later waves build on: the scripted model sits on the provider, never on a faked agent, so
 * a paused turn resumes on the path production runs; the host's own tool pauses without any action of the package.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config(['ai.conversations.generate_title' => false]);

    Trace::reset();

    $this->user = User::factory()->create();
});

it('pauses a host tool\'s call, stores the paused turn and announces it, without running the tool', function () {
    $requested = [];
    Event::listen(ToolApprovalRequested::class, function (ToolApprovalRequested $event) use (&$requested): void {
        $requested[] = $event;
    });

    (new ScriptedGateway([[HostConfirmedTool::NAME, ['note' => 'CANARY-ARG']]], 'All done.', 'I will ask first.'))->install();

    $response = (new HostToolAgent($this->user))->forUser($this->user)->prompt('Send a note.');

    expect(Ai::hasFakeGatewayFor(HostToolAgent::class))->toBeFalse()
        ->and($response->hasPendingApprovals())->toBeTrue()
        ->and($response->pendingApprovals->map(fn ($approval): array => [$approval->id, $approval->tool, $approval->arguments, $approval->reason])->all())
        ->toBe([['call_1', HostConfirmedTool::NAME, ['note' => 'CANARY-ARG'], HostConfirmedTool::REASON]])
        ->and($response->conversationId)->toBeString()
        ->and(Trace::$calls)->toBe([])
        ->and($requested)->toHaveCount(1)
        ->and($requested[0]->conversationId)->toBe($response->conversationId)
        ->and($requested[0]->conversationUser)->toBe($this->user);
});
