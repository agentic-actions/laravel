<?php

use AgenticActions\Console\Checks;
use AgenticActions\Console\Finding;
use AgenticActions\Support\PackageStatus;
use Tests\Fixtures\Actions\DeleteNote;
use Tests\Fixtures\Actions\PublishNote;
use Tests\Fixtures\Approvals\ConfirmedDelete;
use Tests\Fixtures\Approvals\ConfirmedSend;
use Tests\Fixtures\Approvals\ConfirmingAgent;
use Tests\Fixtures\Approvals\DeleteByTitle;
use Tests\Fixtures\Approvals\UnsummarizedDelete;
use Tests\Fixtures\Checks\ApprovalLooseAuthorize;
use Tests\Fixtures\Checks\ApprovalNoInput;
use Tests\Fixtures\Checks\AskDraft;

/*
 * The Summary row: an agent action a person confirms, offered any input, must write approvalSummary() and check its
 * input in authorize(), so a card never shows a record authorize() did not allow.
 */

beforeEach(function () {
    $this->skipUnlessAi();
});

/**
 * The findings of these rows for exactly these classes, as [level, row, message] triples.
 *
 * @param  list<class-string>  $classes
 * @param  list<string>  $rows
 * @return list<array{0: string, 1: string, 2: string}>
 */
function summaryFindings(array $classes, array $rows = ['Summary']): array
{
    config(['agentic-actions.discovery.paths' => [], 'agentic-actions.discovery.classes' => $classes]);

    return array_values(array_map(
        fn (Finding $finding): array => [$finding->level, $finding->row, $finding->message],
        array_filter(app(Checks::class)->run(), fn (Finding $finding): bool => in_array($finding->row, $rows, true)),
    ));
}

it('fails an action with input and no approvalSummary(), though its key is not named like an id', function () {
    expect(summaryFindings([UnsummarizedDelete::class], ['Summary', 'Ids']))->toBe([
        ['fail', 'Summary', UnsummarizedDelete::class.': agents are offered [post], and a person confirms this action on a card, but approvalSummary() is not written, so the card cannot say what the call acts on. Build it from the validated input and the record authorize() allowed (docs/copilot.md#confirmations).'],
    ]);
});

it('fails an action with input whose authorize() does not take ValidatedInput, though its key is not named like an id', function () {
    expect(summaryFindings([ApprovalLooseAuthorize::class], ['Summary', 'Ids']))->toBe([
        ['fail', 'Summary', ApprovalLooseAuthorize::class.': agents are offered [post], and a person confirms this action on a card, but authorize() does not take ValidatedInput, so the card could show a record the person may not read. Check it in authorize(ActionContext $context, ValidatedInput $input).'],
    ]);
});

it('passes an action with a summary and an input-taking authorize(), whatever it advertises', function () {
    expect(summaryFindings([ConfirmedDelete::class, ConfirmedSend::class, DeleteByTitle::class]))->toBe([]);
});

it('passes an action that takes no input, with no summary', function () {
    expect(summaryFindings([ApprovalNoInput::class]))->toBe([]);
});

it('never asks an action that asks for missing fields, and is not confirmed, for a card summary', function () {
    expect(summaryFindings([AskDraft::class, ConfirmingAgent::class]))->toBe([]);
});

it('is silent for Destructive and External actions with input that agents are not offered', function () {
    expect(summaryFindings([DeleteNote::class, PublishNote::class]))->toBe([]);
});

it('is silent without laravel/ai, where no agent surface opens', function () {
    $this->usePackages(['laravel/ai' => PackageStatus::Missing]);

    expect(summaryFindings([UnsummarizedDelete::class, ApprovalLooseAuthorize::class]))->toBe([]);
});
