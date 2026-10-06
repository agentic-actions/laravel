<?php

use AgenticActions\ActionContext;
use AgenticActions\Approvals\ApprovalCard;
use AgenticActions\Approvals\ApprovalClaims;
use AgenticActions\Approvals\ApprovalTicket;
use AgenticActions\Exposure\ClassExposure;
use Illuminate\Support\ValidatedInput;
use Tests\Fixtures\Approvals\ConfirmedDelete;
use Tests\Fixtures\Approvals\ConfirmedPublish;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * approvalBinding(): what a person confirms without reading it on the card. It joins the claim's fingerprint, so a
 * call whose bound data changed between the card and the run is refused, as one whose card would read otherwise is.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->post = Post::factory()->for($this->user)->create(['title' => 'Launch notes', 'body' => 'The body the person meant to publish.']);
    $this->input = new ValidatedInput(['post' => $this->post->getKey()]);
    $this->context = ActionContext::agent($this->user)->forAction('confirmed-publish')->withApproval(new ApprovalTicket('conversation-1', 'call_1'));
    $this->build = fn (): ApprovalCard => ApprovalCard::build(ClassExposure::of(ConfirmedPublish::class), new ConfirmedPublish, $this->context, $this->input);
    $this->claims = app(ApprovalClaims::class);
});

it('refuses a call whose bound data changed after the card, though the card reads the same', function () {
    expect($this->claims->mint('conversation-1', 'call_1', ($this->build)()))->toBeTrue();

    $this->post->update(['body' => 'Something else entirely.']);
    $rebuilt = ($this->build)();

    expect($rebuilt->summary)->toBe([['label' => 'Post', 'value' => 'Launch notes']])
        ->and($this->claims->claim($rebuilt))->toBeFalse();
});

it('takes the claim when nothing bound changed', function () {
    $this->claims->mint('conversation-1', 'call_1', ($this->build)());

    expect($this->claims->claim(($this->build)()))->toBeTrue();
});

it('keeps the input\'s fingerprint for an action that binds nothing', function () {
    $post = Post::factory()->for($this->user)->create();
    $input = new ValidatedInput(['post' => $post->getKey()]);
    $card = ApprovalCard::build(ClassExposure::of(ConfirmedDelete::class), new ConfirmedDelete, ActionContext::agent($this->user)->forAction('confirmed-delete'), $input);

    expect($card->fingerprint)->toBe(ApprovalClaims::fingerprint($input));
});
