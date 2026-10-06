<?php

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Approvals\ApprovalCard;
use AgenticActions\Approvals\ApprovalClaims;
use AgenticActions\Approvals\ApprovalTicket;
use AgenticActions\Exposure\ClassExposure;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Support\ValidatedInput;
use Tests\Fixtures\Approvals\ConfirmedDelete;
use Tests\Fixtures\Approvals\ConfirmedPublish;
use Tests\Fixtures\Datasets\PostStatus;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * approvalBinding(): what a person confirms without reading it on the card. It joins the claim's fingerprint, so a
 * call whose bound data changed between the card and the run is refused, as one whose card would read otherwise is.
 * It holds plain data, so the fingerprint covers every value: any other value gives no card.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->post = Post::factory()->for($this->user)->create(['title' => 'Launch notes', 'body' => 'The body the person meant to publish.']);
    $this->input = new ValidatedInput(['post' => $this->post->getKey()]);
    $this->context = ActionContext::agent($this->user)->forAction('confirmed-publish')->withApproval(new ApprovalTicket('conversation-1', 'call_1'));
    $this->build = fn (): ApprovalCard => ApprovalCard::build(ClassExposure::of(ConfirmedPublish::class), new ConfirmedPublish, $this->context, $this->input);
    $this->claims = app(ApprovalClaims::class);
});

/**
 * Build a card for ConfirmedPublish's entry from an action whose binding is $binding.
 *
 * @param  array<string, mixed>  $binding
 */
function seams04Bound(array $binding): ApprovalCard
{
    $action = new class($binding) extends Action
    {
        /**
         * @param  array<string, mixed>  $binding
         */
        public function __construct(private readonly array $binding) {}

        public function approvalBinding(ActionContext $context, ValidatedInput $input): array
        {
            return $this->binding;
        }
    };

    return ApprovalCard::build(ClassExposure::of(ConfirmedPublish::class), $action, ActionContext::agent(User::factory()->create()), new ValidatedInput(['post' => 1]));
}

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

it('binds an HtmlString, a backed enum and another Stringable as their text', function (mixed $value, string $text) {
    expect(seams04Bound(['body' => $value])->fingerprint)->toBe(seams04Bound(['body' => $text])->fingerprint);
})->with([
    'an HtmlString' => [new HtmlString('<p>The body.</p>'), '<p>The body.</p>'],
    'an HtmlString around another, as a cast value is wrapped again' => [new HtmlString(new HtmlString('<p>The body.</p>')), '<p>The body.</p>'],
    'a backed enum' => [PostStatus::Draft, 'draft'],
    'a Stringable whose state is private' => [new class('The body.') implements Stringable
    {
        public function __construct(private readonly string $body) {}

        public function __toString(): string
        {
            return $this->body;
        }
    }, 'The body.'],
]);

it('builds no card, rather than bind nothing, for a binding that holds a model or another object', function (Closure $binding, string $path) {
    expect(fn () => seams04Bound($binding->call($this)))->toThrow(LogicException::class, ConfirmedPublish::class.": approvalBinding() value [{$path}]");
})->with([
    'an object whose state is private' => [fn (): array => ['draft' => new class('The body.')
    {
        public function __construct(private readonly string $body) {}
    }], 'draft'],
    'an Eloquent model, in a list' => [fn (): array => ['recipients' => [$this->post]], 'recipients.0'],
    'a date' => [fn (): array => ['send_at' => Carbon::parse('2026-10-06 10:00:00', 'Asia/Damascus')], 'send_at'],
    'a Str::of() string' => [fn (): array => ['subject' => Str::of('Launch notes')], 'subject'],
    'an HtmlString around an object whose state is private' => [fn (): array => ['body' => new HtmlString(new class('The body.')
    {
        public function __construct(private readonly string $body) {}
    })], 'body'],
]);
