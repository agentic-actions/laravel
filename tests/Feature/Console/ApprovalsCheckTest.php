<?php

use AgenticActions\Console\Checks;
use AgenticActions\Console\Finding;
use AgenticActions\Discovery\Snapshot;
use AgenticActions\Support\PackageStatus;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Contracts\ConversationStore;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\DeleteNote;
use Tests\Fixtures\Approvals\ConfirmedDelete;
use Tests\Fixtures\Approvals\ConfirmedSend;
use Tests\Fixtures\Approvals\ConfirmingAgent;
use Tests\Fixtures\Approvals\ContractOnlyAgent;
use Tests\Fixtures\Approvals\ForgetfulStore;
use Tests\Fixtures\Approvals\StatelessAgent;
use Tests\Fixtures\Approvals\TraitOnlyAgent;
use Tests\Fixtures\Checks\AskDraft;
use Tests\Fixtures\Checks\AskWebOnly;

/*
 * The Approvals row: an agent whose toolsets hold actions that wait on the person, a confirmed one or one whose
 * incomplete calls ask in a form (0.5), but that does not store its conversations (a warning: it is simply never
 * offered them), and a conversation store that cannot read pending calls or ownership (one failure).
 */

beforeEach(function () {
    $this->skipUnlessAi();

    File::delete(Snapshot::path());
});

afterEach(function () {
    File::delete(Snapshot::path());
});

/**
 * The Approvals row's findings for exactly these classes, as [level, message] pairs.
 *
 * @param  list<class-string>  $classes
 * @return list<array{0: string, 1: string}>
 */
function approvalsFindings(array $classes): array
{
    config(['agentic-actions.discovery.paths' => [], 'agentic-actions.discovery.classes' => $classes]);

    return array_values(array_map(
        fn (Finding $finding): array => [$finding->level, $finding->message],
        array_filter(app(Checks::class)->run(), fn (Finding $finding): bool => $finding->row === 'Approvals'),
    ));
}

/**
 * The warning for an agent whose toolsets hold these actions.
 *
 * @param  class-string  $agent
 */
function neverOffered(string $agent, string $names): string
{
    return "{$agent}: its toolsets hold [{$names}], which wait on the person (a confirmation, or a form for missing fields), but it does not store its conversations, so it is never offered the first kind and never asks for the second: those calls are refused instead. Implement Laravel\\Ai\\Contracts\\Conversational and use Laravel\\Ai\\Concerns\\RemembersConversations.";
}

/**
 * The failure for a conversation store that cannot hold a waiting call, naming these actions.
 */
function cannotWait(string $names): string
{
    return "The conversation store bound for laravel/ai cannot read which calls a conversation is waiting on, or whom it belongs to, so no agent can wait on the person for [{$names}]. Keep laravel/ai's database store, or implement ResolvesPendingApprovals and VerifiesConversationOwnership on yours.";
}

it('warns about an agent that does not store its conversations, naming what its toolsets hold', function (string $agent) {
    expect(approvalsFindings([ConfirmedDelete::class, ConfirmedSend::class, AskDraft::class, CreateNote::class, $agent]))
        ->toBe([['warn', neverOffered($agent, 'ask-draft, confirmed-delete, confirmed-send')]]);
})->with([
    'no Conversational' => StatelessAgent::class,
    'the trait without the contract' => TraitOnlyAgent::class,
    'the contract without the trait' => ContractOnlyAgent::class,
]);

it('warns about an asking action with no confirmed action beside it', function () {
    expect(approvalsFindings([AskDraft::class, StatelessAgent::class]))
        ->toBe([['warn', neverOffered(StatelessAgent::class, 'ask-draft')]]);
});

it('passes an agent that stores its conversations', function () {
    expect(approvalsFindings([ConfirmedDelete::class, ConfirmedSend::class, AskDraft::class, ConfirmingAgent::class]))->toBe([]);
});

it('fails once when the bound store cannot read pending calls or ownership, whatever the agents', function (array $classes, array $findings) {
    app()->instance(ConversationStore::class, new ForgetfulStore);

    expect(approvalsFindings($classes))->toBe($findings);
})->with([
    'confirmed and asking actions' => [
        [ConfirmedDelete::class, ConfirmedSend::class, AskDraft::class, ConfirmingAgent::class, StatelessAgent::class],
        [
            ['warn', neverOffered(StatelessAgent::class, 'ask-draft, confirmed-delete, confirmed-send')],
            ['fail', cannotWait('ask-draft, confirmed-delete, confirmed-send')],
        ],
    ],
    'an asking action alone, since no agent can ever ask on that store' => [[AskDraft::class, ConfirmingAgent::class], [['fail', cannotWait('ask-draft')]]],
]);

it('is silent with no action a person confirms, or that asks, on the agent surface', function () {
    app()->instance(ConversationStore::class, new ForgetfulStore);

    expect(approvalsFindings([CreateNote::class, DeleteNote::class, AskWebOnly::class, StatelessAgent::class]))->toBe([]);
});

it('is silent without laravel/ai, where no agent surface opens', function () {
    $this->usePackages(['laravel/ai' => PackageStatus::Missing]);

    expect(approvalsFindings([ConfirmedDelete::class, AskDraft::class, StatelessAgent::class]))->toBe([]);
});
