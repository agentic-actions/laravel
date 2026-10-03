<?php

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Approvals\ApprovalCard;
use AgenticActions\Approvals\ApprovalClaims;
use AgenticActions\Approvals\ApprovalTicket;
use AgenticActions\Elicitation\Form;
use AgenticActions\Elicitation\FormSchema;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Exposure\Entry;
use AgenticActions\Facades\Actions;
use AgenticActions\Pipeline\Door;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Runner;
use AgenticActions\Streaming\ChatRequest;
use AgenticActions\Streaming\Transcript;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Laravel\Ai\Approvals\Decision;
use Tests\Fixtures\Actions\Trace;
use Tests\Fixtures\Approvals\ConfirmedDelete;
use Tests\Fixtures\Approvals\ScriptedGateway;
use Tests\Fixtures\Elicitation\AskingAgent;
use Tests\Fixtures\Elicitation\AskingDelete;
use Tests\Fixtures\Elicitation\AskingDraft;
use Tests\Fixtures\Elicitation\AskingSchedule;
use Tests\Fixtures\FormAttacks\AgentVocabulary;
use Tests\Fixtures\FormAttacks\CountedDraft;
use Tests\Fixtures\FormAttacks\NestingAskingWrite;
use Tests\Fixtures\Security\Inside;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * The 0.5 security review. A hostile browser, a prompt-injected model and a second signed-in person try to get the
 * person's answers to the model, to steer a form with the model's words, to make a form ask for a secret, to answer
 * a form twice, for someone else or in a shape it does not hold, to make a Destructive action ask or run on a form's
 * answer, and to make one request cost the server many forms. The model is scripted on the provider, so every resume
 * runs laravel/ai's own resume path.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config([
        'agentic-actions.discovery.paths' => [
            dirname(__DIR__, 2).'/Fixtures/Elicitation',
            dirname(__DIR__, 2).'/Fixtures/FormAttacks',
            dirname(__DIR__, 2).'/Fixtures/Approvals',
        ],
        'ai.conversations.generate_title' => false,
    ]);

    $this->refreshActions();
    AskingDraft::reset();
    AskingDelete::$handled = false;
    AskingSchedule::$handled = null;
    CountedDraft::reset();
    AgentVocabulary::$handled = false;
    NestingAskingWrite::$handled = 0;
    Inside::reset();
    Trace::reset();
    Exceptions::fake();

    $this->user = User::factory()->create(['name' => 'Ada']);

    // Pause the user's asking agent on the scripted calls; the conversation's id.
    $this->pause = function (array $calls = [['asking-draft', ['title' => 'Launch notes']]]): string {
        $this->gateway = (new ScriptedGateway($calls, 'Saved it.', 'A few details first.'))->install();

        return (string) (new AskingAgent($this->user))->forUser($this->user)->prompt('Draft a post.')->conversationId;
    };

    // The agent the host's route builds: continued in the conversation, for the person.
    $this->agent = fn (string $id, ?User $as = null): AskingAgent => (new AskingAgent($as ?? $this->user))->continue($id, as: $as ?? $this->user);

    $this->answer = fn (?array $result, string $id = 'call_1', ?bool $approved = null, string $tool = 'asking-draft'): array => [
        'type' => 'tool-'.$tool,
        'toolCallId' => $id,
        'state' => 'approval-responded',
        'approval' => ['id' => $id, 'approved' => $approved ?? ($result['action'] ?? null) === 'accept'],
        ...($result === null ? [] : ['elicitation' => $result]),
    ];

    $this->accept = fn (array $content, string $id = 'call_1', string $tool = 'asking-draft'): array => ($this->answer)(['action' => 'accept', 'content' => $content], $id, tool: $tool);

    $this->body = fn (array $parts): array => ['messages' => [['id' => 'msg-a1', 'role' => 'assistant', 'parts' => $parts]]];

    $this->read = function (array $body, AskingAgent $agent, ?User $as = null, bool $precognition = false): ChatRequest {
        $request = Request::create('/assistant', 'POST', server: ['CONTENT_TYPE' => 'application/json', ...($precognition ? ['HTTP_PRECOGNITION' => 'true'] : [])], content: json_encode($body, JSON_THROW_ON_ERROR));
        $request->setUserResolver(fn (): User => $as ?? $this->user);

        return ChatRequest::from($request, $agent);
    };

    $this->respond = function (ChatRequest $chat): array {
        $ran = false;
        $response = $chat->respond(function () use (&$ran) {
            $ran = true;

            return response()->noContent();
        });

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true), $ran];
    };

    $this->waits = fn (string $id, string $call = 'call_1'): bool => Cache::has(ApprovalClaims::PREFIX.hash('sha256', $id."\n".$call));

    // Whether the turn could still be reserved; the probe's reservation is released at once.
    $this->reservable = function (string $id, array $calls = ['call_1']): bool {
        $reservation = app(ApprovalClaims::class)->reserve($id, $calls);

        if ($reservation !== null) {
            app(ApprovalClaims::class)->release($reservation);
        }

        return $reservation !== null;
    };
    $this->decided = fn (ChatRequest $chat): array => array_map(fn (Decision $decision): array => [$decision->action, $decision->result], $chat->decisions()?->all() ?? []);

    $this->valid = ['title' => 'Launch notes', 'body' => 'What shipped this week.', 'status' => 'draft'];
});

afterEach(function () {
    ignore_user_abort(false);
});

/**
 * Preview a model's call of a class for the asking toolset, as the tool does at the pause.
 *
 * @param  class-string<Action>  $class
 * @param  array<string, mixed>  $arguments
 */
function formAttacksPreview(string $class, array $arguments): ApprovalCard|Form|null
{
    $context = ActionContext::agent(test()->user)->withApproval(new ApprovalTicket('conversation-1', 'call_1'));

    return app(Runner::class)->preview(ClassExposure::of($class), $arguments, $context, ['asking']);
}

describe('1. the person\'s answers and the model', function () {
    it('keeps a canary out of a reload after an answer the action refused, and out of the next turn the model is sent', function () {
        $id = ($this->pause)();

        [$status] = ($this->respond)(($this->read)(($this->body)([($this->accept)([...$this->valid, 'body' => 'CANARY-'.str_repeat('b', 5000)])]), ($this->agent)($id)));
        $reloaded = json_encode(Transcript::forUseChat($id, $this->user, agent: ($this->agent)($id)), JSON_THROW_ON_ERROR);

        expect($status)->toBe(422)
            ->and($reloaded)->toContain('elicitation:call_1')
            ->and($reloaded)->not->toContain('CANARY');

        $agent = ($this->agent)($id);
        $agent->prompt(($this->read)(($this->body)([($this->accept)([...$this->valid, 'body' => 'CANARY-BODY'])]), $agent));

        // The person writes again: the model is sent the whole stored conversation.
        $next = (new ScriptedGateway([], 'You drafted it.'))->install();
        ($this->agent)($id)->prompt('What did I write?');
        $sent = json_encode($next->sent, JSON_THROW_ON_ERROR);

        expect(AskingDraft::$handled['body'] ?? null)->toBe('CANARY-BODY')
            ->and($sent)->toContain('What did I write?')
            ->and($sent)->toContain('The person filled in: title, body, status.')
            ->and($sent)->not->toContain('CANARY')
            ->and(json_encode([$this->gateway->sent, $next->sent], JSON_THROW_ON_ERROR))->not->toContain('A few details for your post.')
            ->and(json_encode([$this->gateway->sent, $next->sent], JSON_THROW_ON_ERROR))->not->toContain(trans('agentic-actions::ask.source', ['app' => config('app.name')]))
            ->and(json_encode(Transcript::forUseChat($id, $this->user, agent: ($this->agent)($id)), JSON_THROW_ON_ERROR))->not->toContain('CANARY');
    });

    it('answers content in shapes the form does not hold with 422 by field, and runs a list sent as an object as its values', function () {
        $id = ($this->pause)([['asking-schedule', ['tags' => 'x', 'count' => 99]]]);

        [$status, $json, $ran] = ($this->respond)(($this->read)(($this->body)([($this->accept)([
            'when' => ['2026-10-01'],
            'count' => '5; drop table posts',
            'tags' => ['news'],
            'share' => 'yes',
            'team_id' => 9,
        ], tool: 'asking-schedule')]), ($this->agent)($id)));

        expect($status)->toBe(422)
            ->and(array_keys($json['errors']))->toEqualCanonicalizing(['when', 'count'])
            ->and($ran)->toBeFalse()
            ->and(AskingSchedule::$handled)->toBeNull()
            ->and(($this->waits)($id))->toBeTrue()
            ->and(($this->reservable)($id))->toBeTrue();

        // The keys of an object sent for a list never reach the action, a message or the model: only its values do.
        $agent = ($this->agent)($id);
        $agent->prompt(($this->read)(($this->body)([($this->accept)(['when' => '2026-10-01', 'count' => 5, 'tags' => ['CANARY' => 'news']], tool: 'asking-schedule')]), $agent));

        expect(AskingSchedule::$handled)->toEqual(['when' => '2026-10-01', 'count' => 5, 'tags' => ['news'], 'share' => true])
            ->and(json_encode($this->gateway->sent, JSON_THROW_ON_ERROR))->not->toContain('CANARY');
    });
});

describe('2. the form and the model\'s words', function () {
    it('opens no field on a value of the model\'s holding a control, a line separator or a direction control', function (string $title) {
        $form = formAttacksPreview(AskingDraft::class, ['title' => $title]);

        expect($form)->toBeInstanceOf(Form::class)
            ->and($form->fields)->toBe(['title', 'body', 'status'])
            ->and($form->schema['properties']['title'])->not->toHaveKey('default');
    })->with([
        'a right-to-left override' => ["Launch notes\u{202E}txt.exe"],
        'an isolate' => ["\u{2067}Launch notes"],
        'a line separator' => ["Launch\u{2028}notes"],
        'a bell' => ["Launch\x07notes"],
    ]);

    it('opens a field on the model\'s plain value, and sends only the standard\'s keys and the one hint', function () {
        $form = formAttacksPreview(AskingDraft::class, ['title' => 'Launch notes: <b>v2</b> https://example.test']);
        $schedule = formAttacksPreview(AskingSchedule::class, ['when' => 5, 'at' => 'x', 'count' => 99, 'share' => 'maybe', 'tags' => 'x', 'code' => 'abc']);
        $standard = ['type', 'title', 'description', 'minLength', 'maxLength', 'format', 'minimum', 'maximum', 'enum', 'oneOf', 'items', 'minItems', 'maxItems', 'default', 'x-agentic-actions'];

        expect($form->schema['properties']['title']['default'])->toBe('Launch notes: <b>v2</b> https://example.test')
            ->and($schedule)->toBeInstanceOf(Form::class)
            ->and($schedule->message)->toBe(trans('agentic-actions::ask.message'));

        foreach ([...$form->schema['properties'], ...$schedule->schema['properties']] as $property) {
            expect(array_diff(array_keys($property), $standard))->toBe([]);
        }
    });
});

describe('3. secrets', function () {
    beforeEach(function () {
        // No forbidden key, so only the secret check can keep a field out of a form.
        config(['agentic-actions.agents.forbidden_keys' => []]);

        // What a form makes of a string field: null when it may not ask it.
        $this->field = fn (string $key, array $node = [], array $rules = []): ?array => FormSchema::property($key, ['type' => 'string', ...$node], [], [], $rules);
    });

    it('never asks a field whose key reads as a secret in the plural, split in two, or in capitals', function (string $key) {
        expect(($this->field)($key))->toBeNull();
    })->with(['api_keys', 'apiKeys', 'recovery_codes', 'backup_codes', 'passwords', 'tokens', 'client_secrets', 'pins', 'pass_word', 'passWord', 'pass_code', 'pass_phrase', 'PINCode', 'OTPCode', 'APIToken', 'CVVNumber', 'seed_phrase', 'recovery_phrase', 'security_answer', 'card_numbers']);

    it('never asks an innocuous key titled or described as such a secret', function () {
        expect(($this->field)('reference', ['title' => 'Backup codes']))->toBeNull()
            ->and(($this->field)('reference', ['description' => 'Your wallet\'s recovery phrase.']))->toBeNull()
            ->and(($this->field)('reference', ['description' => 'Your order reference.']))->not->toBeNull();
    });

    it('still asks a field whose words only look like one', function (string $key) {
        expect(($this->field)($key))->not->toBeNull();
    })->with(['shipping', 'option', 'opinion', 'pinned', 'spinner', 'passage', 'passenger', 'compass', 'bypass', 'boarding_pass', 'status', 'keyboard', 'promo_codes', 'country_codes', 'access_level', 'PDFTitle']);

    it('never asks a field whose rules check a password in array form, in a nested list, behind Rule::when(), or through Password::required()', function (array $rules) {
        expect(($this->field)('confirm_with', rules: $rules))->toBeNull()
            ->and(($this->field)('confirm_with', rules: ['required', 'string']))->not->toBeNull();
    })->with([
        'the array form' => [[['current_password', 'web']]],
        'Rule::when(), on' => [[Rule::when(true, ['current_password'])]],
        'Rule::when(), off' => [[Rule::when(false, ['string'], [Password::min(8)])]],
        'Rule::when() with a pipe string' => [[Rule::when(fn (): bool => true, 'required|current_password')]],
        'a nested list' => [[['required', Password::min(8)]]],
        'Password::required()' => [[Password::required()]],
    ]);

    it('asks nothing through an agent schema: not a canonical key fromAgent() fills, nor an agent key that reads as a secret', function (array $arguments) {
        $outcome = app(Runner::class)->run(ClassExposure::of(AgentVocabulary::class), $arguments, ActionContext::agent($this->user), Door::Agent, ['asking']);

        expect(formAttacksPreview(AgentVocabulary::class, $arguments))->toBeNull()
            ->and($outcome->kind())->not->toBe(OutcomeKind::Ok)
            ->and(AgentVocabulary::$handled)->toBeFalse();
    })->with([
        'the password fromAgent() leaves out' => [['title' => 'Launch notes']],
        'a pin the model got wrong' => [['title' => 'Launch notes', 'pin' => 'abc']],
    ]);
});

describe('4. answering', function () {
    it('reads one answer per call, however many parts a body sends for it', function () {
        $id = ($this->pause)([['counted-draft', ['title' => 'Launch notes']]]);
        $invalid = ($this->accept)(['title' => 'Launch notes', 'body' => str_repeat('b', 51)], tool: 'counted-draft');
        $valid = ($this->accept)(['title' => 'Launch notes', 'body' => 'Fine.'], tool: 'counted-draft');
        CountedDraft::$asked = 0;

        [$status] = ($this->respond)(($this->read)(($this->body)(array_fill(0, 200, $invalid)), ($this->agent)($id)));

        expect($status)->toBe(422)
            ->and(CountedDraft::$asked)->toBe(2);

        [$status] = ($this->respond)(($this->read)(($this->body)([$invalid, $valid]), ($this->agent)($id), precognition: true));

        expect($status)->toBe(422);

        $chat = ($this->read)(($this->body)([($this->answer)(['action' => 'decline'], tool: 'counted-draft'), $valid]), ($this->agent)($id));

        expect(($this->decided)($chat)['call_1'][0])->toBe('reject')
            ->and(CountedDraft::$handled)->toBeNull();
    });

    it('never lets another member spend the person\'s claim with a decline or a cancel', function (string $action) {
        $id = ($this->pause)();
        $other = User::factory()->create();

        [$status, , $ran] = ($this->respond)(($this->read)(($this->body)([($this->answer)(['action' => $action])]), ($this->agent)($id, $other), $other));

        expect($status)->toBe(409)
            ->and($ran)->toBeFalse()
            ->and(($this->waits)($id))->toBeTrue()
            ->and(($this->reservable)($id))->toBeTrue();
    })->with(['decline', 'cancel']);

    it('writes nothing to the cache for a check, or for an answer the action refuses', function () {
        $id = ($this->pause)();
        $writes = [];

        Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$writes): void {
            $writes[] = $event->key;
        });

        ($this->respond)(($this->read)(($this->body)([($this->accept)($this->valid)]), ($this->agent)($id), precognition: true));
        ($this->respond)(($this->read)(($this->body)([($this->accept)([...$this->valid, 'status' => 'archived'])]), ($this->agent)($id)));
        ($this->respond)(($this->read)(($this->body)([($this->answer)(['action' => 'decline'])]), ($this->agent)($id), precognition: true));

        expect($writes)->toBe([])
            ->and(($this->waits)($id))->toBeTrue()
            ->and(($this->reservable)($id))->toBeTrue();
    });
});

describe('5. Destructive and External actions', function () {
    it('never ask through a manifest row that says they do', function () {
        $row = [...ClassExposure::of(AskingDelete::class)->toManifest(), 'ask_for_missing' => true];

        expect(Entry::fromManifest($row)->asks)->toBeFalse()
            ->and(Entry::fromManifest([...$row, 'effect' => 'external'])->asks)->toBeFalse()
            ->and(Entry::fromManifest(ClassExposure::of(AskingDraft::class)->toManifest())->asks)->toBeTrue();
    });

    it('run nothing from a form\'s answer, even on a ticket naming the form\'s call', function () {
        $post = $this->user->posts()->create(['title' => 'Keep me', 'body' => 'x', 'status' => 'draft']);
        $id = ($this->pause)([['nesting-asking-write', []]]);
        $named = fn (ActionContext $context): ActionContext => $context->withApproval(new ApprovalTicket($id, 'call_1'));

        Inside::$run = fn (ActionContext $context): array => [
            Actions::attempt(ConfirmedDelete::class, ['post' => $post->id], $context)->kind(),
            Actions::call(['approvals'], 'confirmed-delete', ['post' => $post->id], $context)->kind(),
            Actions::call(['approvals'], 'confirmed-delete', ['post' => $post->id], $named($context))->kind(),
            Actions::call(['asking'], 'asking-delete', ['post' => $post->id], $named($context))->kind(),
        ];

        $agent = ($this->agent)($id);
        $agent->prompt(($this->read)(($this->body)([($this->accept)(['note' => 'Tidy up.'], tool: 'nesting-asking-write')]), $agent));

        expect(NestingAskingWrite::$handled)->toBe(1)
            ->and(Inside::$results)->toHaveCount(1)
            ->and(array_filter(Inside::$results[0], fn (OutcomeKind $kind): bool => $kind === OutcomeKind::Ok))->toBe([])
            ->and(Trace::$calls)->not->toContain('ConfirmedDelete::handle')
            ->and(AskingDelete::$handled)->toBeFalse()
            ->and(Post::query()->whereKey($post->id)->exists())->toBeTrue();
    });
});
