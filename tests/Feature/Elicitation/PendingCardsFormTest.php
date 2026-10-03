<?php

use AgenticActions\Ai\PendingCards;
use AgenticActions\Approvals\ApprovalClaims;
use AgenticActions\Elicitation\Form;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Tests\Fixtures\Approvals\ScriptedGateway;
use Tests\Fixtures\Elicitation\AskingAgent;
use Tests\Fixtures\Elicitation\AskingDraft;
use Tests\Fixtures\Streaming\Parts;
use Workbench\App\Models\User;

/*
 * A form's claim is minted once, when laravel/ai reports a real pause of an asking call this request previewed, and its
 * data-elicitation part goes to the open stream, which accepts it; cards and forms share PendingCards::MAX_CARDS; a
 * resume mints nothing.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config([
        'app.name' => 'Blog',
        'agentic-actions.discovery.paths' => [dirname(__DIR__, 2).'/Fixtures/Elicitation'],
        'ai.conversations.generate_title' => false,
    ]);

    $this->refreshActions();
    AskingDraft::reset();
    Exceptions::fake();

    $this->user = User::factory()->create();
    $this->claimKey = fn (string $conversation, string $call = 'call_1'): string => ApprovalClaims::PREFIX.hash('sha256', $conversation."\n".$call);

    $this->claimWrites = [];
    Event::listen(KeyWritten::class, function (KeyWritten $event): void {
        if (str_starts_with($event->key, ApprovalClaims::PREFIX)) {
            $this->claimWrites[] = $event->key;
        }
    });

    // The parts of a streamed turn in which the model makes these calls.
    $this->streamed = function (array $calls): array {
        (new ScriptedGateway($calls, 'Done.', 'I will ask.'))->install();

        return Parts::of(Parts::body((new AskingAgent($this->user))->forUser($this->user)->stream('Draft a post.')));
    };

    $this->conversation = fn (): string => (string) DB::table('agent_conversations')->value('id');
});

it('mints a card and a form of one step, and streams both', function () {
    $post = $this->user->posts()->create(['title' => 'Old notes', 'body' => 'x', 'status' => 'draft']);

    $parts = ($this->streamed)([['asking-delete', ['post' => $post->id]], ['asking-draft', ['title' => 'Launch notes']]]);
    $conversation = ($this->conversation)();

    expect($this->claimWrites)->toBe([($this->claimKey)($conversation, 'call_1'), ($this->claimKey)($conversation, 'call_2')])
        ->and(collect($parts)->firstWhere('id', 'approval:call_1')['type'])->toBe('data-approval')
        ->and(collect($parts)->firstWhere('id', 'elicitation:call_2')['type'])->toBe('data-elicitation');

    Exceptions::assertNothingReported();
});
