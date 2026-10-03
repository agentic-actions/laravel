<?php

use AgenticActions\ActionContext;
use AgenticActions\Approvals\ApprovalCard;
use AgenticActions\Approvals\ApprovalClaims;
use AgenticActions\Approvals\ApprovalTicket;
use AgenticActions\Elicitation\Form;
use AgenticActions\Exposure\ClassExposure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ValidatedInput;
use Tests\Fixtures\Approvals\ConfirmedDelete;
use Tests\Fixtures\Elicitation\AskingDraft;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * A form's claim: minted at the pause as a card's, taken once at step 8 for the same action, person, tenant,
 * conversation, call and model arguments, spent by a decline, gone after approvals.ttl; a card's claim and a form's
 * never stand in for each other; and a card's binding is byte-identical to 0.4's. Every case runs on the array store
 * and on the database store.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->claims = app(ApprovalClaims::class);

    // A form as the preview builds it for this person, from these model arguments.
    $this->form = fn (array $arguments = ['title' => 'Launch notes'], ?ActionContext $context = null, string $message = 'A few details for your post.'): Form => new Form(
        ClassExposure::of(AskingDraft::class),
        ($context ?? ActionContext::agent($this->user))->forAction('asking-draft'),
        $message,
        ['type' => 'object', 'properties' => ['body' => ['type' => 'string', 'title' => 'Body']]],
        ['body'],
        [],
        [],
        ApprovalClaims::fingerprint($arguments),
    );
});

dataset('form claim stores', ['array', 'database']);

it('lets a form\'s claim be taken once', function (string $store) {
    config(['cache.default' => $store]);

    expect($this->claims->mint('conversation-1', 'call_1', ($this->form)()))->toBeTrue()
        ->and($this->claims->holds('conversation-1', 'call_1', ($this->form)()))->toBeTrue()
        ->and($this->claims->take('conversation-1', 'call_1', ($this->form)()))->toBeTrue()
        ->and($this->claims->take('conversation-1', 'call_1', ($this->form)()))->toBeFalse()
        ->and($this->claims->holds('conversation-1', 'call_1', ($this->form)()))->toBeFalse();
})->with('form claim stores');

it('keeps the claim when the form reads otherwise, since no text is bound', function (string $store) {
    config(['cache.default' => $store]);

    $this->claims->mint('conversation-1', 'call_1', ($this->form)());

    expect($this->claims->take('conversation-1', 'call_1', ($this->form)(message: 'Reworded.')))->toBeTrue();
})->with('form claim stores');

it('refuses a form rebuilt from other arguments, for another person, tenant, conversation or call, and leaves the claim', function (string $store) {
    config(['cache.default' => $store]);

    $this->claims->mint('conversation-1', 'call_1', ($this->form)());

    expect($this->claims->take('conversation-1', 'call_1', ($this->form)(['title' => 'Other notes'])))->toBeFalse()
        ->and($this->claims->take('conversation-1', 'call_1', ($this->form)(context: ActionContext::agent(User::factory()->create()))))->toBeFalse()
        ->and($this->claims->take('conversation-1', 'call_1', ($this->form)(context: ActionContext::agent($this->user, Team::factory()->create()))))->toBeFalse()
        ->and($this->claims->take('conversation-2', 'call_1', ($this->form)()))->toBeFalse()
        ->and($this->claims->take('conversation-1', 'call_2', ($this->form)()))->toBeFalse()
        ->and($this->claims->take('conversation-1', 'call_1', ($this->form)()))->toBeTrue();
})->with('form claim stores');

it('never takes a card\'s claim with a form of the same ids, nor the reverse', function (string $store) {
    config(['cache.default' => $store]);

    $context = ActionContext::agent($this->user)->forAction('confirmed-delete')->withApproval(new ApprovalTicket('conversation-1', 'call_1'));
    $card = new ApprovalCard(ClassExposure::of(ConfirmedDelete::class), $context, 'Delete this post?', [], ApprovalClaims::fingerprint(['title' => 'Launch notes']));

    $this->claims->mint('conversation-1', 'call_1', $card);
    $this->claims->mint('conversation-1', 'call_2', ($this->form)());

    expect($this->claims->take('conversation-1', 'call_1', ($this->form)()))->toBeFalse()
        ->and($this->claims->take('conversation-1', 'call_2', new ApprovalCard($card->entry, $context->withApproval(new ApprovalTicket('conversation-1', 'call_2')), $card->title, [], $card->fingerprint)))->toBeFalse()
        ->and($this->claims->claim($card))->toBeTrue()
        ->and($this->claims->take('conversation-1', 'call_2', ($this->form)()))->toBeTrue();
})->with('form claim stores');

it('binds a card exactly as 0.4 did', function () {
    $card = new ApprovalCard(
        ClassExposure::of(ConfirmedDelete::class),
        ActionContext::system()->forAction('confirmed-delete'),
        'Delete this post? This cannot be undone.',
        [['label' => 'Post', 'value' => 'منشور الإطلاق']],
        ApprovalClaims::fingerprint(new ValidatedInput(['post' => 7])),
    );

    $this->claims->mint('conversation-1', 'call_1', $card);

    expect(Cache::get(ApprovalClaims::PREFIX.hash('sha256', "conversation-1\ncall_1")))
        ->toBe('817d8df68c1262dd29f84c868b64a9b24c54372a3718fff5f5f529bb8a91b018');
});

it('fingerprints a model\'s arguments as it fingerprints validated input', function () {
    expect(ApprovalClaims::fingerprint(['b' => ['y' => 1, 'x' => [2, 3]], 'a' => 1]))
        ->toBe(ApprovalClaims::fingerprint(new ValidatedInput(['a' => 1, 'b' => ['x' => [2, 3], 'y' => 1]])));
});
