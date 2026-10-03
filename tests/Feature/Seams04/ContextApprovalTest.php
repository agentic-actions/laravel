<?php

use AgenticActions\ActionContext;
use AgenticActions\Approvals\ApprovalTicket;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Arr;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * The 0.4 context seams: withApproval() carries the confirmation ticket and changes nothing else, and the ticket never
 * enters the idempotency key, which stays what it was before 0.4.
 */

it('carries a ticket and changes nothing else', function () {
    $context = ActionContext::agent(User::factory()->create(), Team::factory()->create(), 'ar')
        ->withFixed(['post' => 7])
        ->withIdempotencyKey('key-1')
        ->forAction('confirmed-delete');
    $ticket = new ApprovalTicket('conversation-1', 'call_1');

    $ticketed = $context->withApproval($ticket);

    expect($context->approval)->toBeNull()
        ->and($ticketed->approval)->toBe($ticket)
        ->and(Arr::except(get_object_vars($ticketed), 'approval'))->toBe(Arr::except(get_object_vars($context), 'approval'))
        ->and($ticketed->withApproval(null)->approval)->toBeNull()
        ->and(Arr::except(get_object_vars($ticketed->withApproval(null)), 'approval'))->toBe(Arr::except(get_object_vars($context), 'approval'));
});

it('derives the same idempotency key as before for a golden input', function () {
    $actor = new GenericUser(['id' => 7]);
    $team = (new Team)->forceFill(['id' => 3]);

    expect(ActionContext::http($actor, $team)->forAction('create-note')->withIdempotencyKey('key-1')->requireIdempotencyKey())
        ->toBe('8b8ca7bf-bb31-59f4-b7a1-f7a8cd35d87d')
        ->and(ActionContext::http($actor)->forAction('create-note')->withIdempotencyKey('key-1')->requireIdempotencyKey())
        ->toBe('4a8467b1-e87a-5681-ae0a-1870fe5126ac')
        ->and(ActionContext::http($actor, $team)->forAction('create-note')->withIdempotencyKey('key-1')->withApproval(new ApprovalTicket('c', 'k'))->requireIdempotencyKey())
        ->toBe('8b8ca7bf-bb31-59f4-b7a1-f7a8cd35d87d');
});
