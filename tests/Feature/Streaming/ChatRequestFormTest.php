<?php

use AgenticActions\Ai\PendingCards;
use AgenticActions\Approvals\ApprovalClaims;
use AgenticActions\Elicitation\Form;
use AgenticActions\Streaming\ChatRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Contracts\ConversationStore;
use Tests\Fixtures\Approvals\ScriptedGateway;
use Tests\Fixtures\Elicitation\AskingAgent;
use Tests\Fixtures\Elicitation\AskingChoices;
use Tests\Fixtures\Elicitation\AskingDelete;
use Tests\Fixtures\Elicitation\AskingDraft;
use Tests\Fixtures\Elicitation\AskingRules;
use Workbench\App\Models\User;

/*
 * The answer to a form: MCP's ElicitResult on the answered tool part, read only from a session of the
 * person the server-chosen conversation belongs to, for a form the call still waits on while its claim holds. An
 * accept is trimmed to the form and checked by a dry run of the action's own pipeline before any reservation; a
 * Precognition request only checks; any other answer to a form's call declines it.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config([
        'agentic-actions.discovery.paths' => [dirname(__DIR__, 2).'/Fixtures/Elicitation'],
        'ai.conversations.generate_title' => false,
    ]);

    $this->refreshActions();
    AskingDraft::reset();
    AskingRules::$handled = null;
    AskingChoices::$handled = null;
    AskingDelete::$handled = false;
    Exceptions::fake();

    $this->user = User::factory()->create(['name' => 'Ada']);
    $this->team = null;

    // Pause the user's asking agent on the scripted calls; the conversation's id.
    $this->pause = function (array $calls = [['asking-draft', ['title' => 'Launch notes']]]): string {
        $this->gateway = (new ScriptedGateway($calls, 'Saved it.', 'A few details first.'))->install();

        return (string) (new AskingAgent($this->user, $this->team))->forUser($this->user)->prompt('Draft a post.')->conversationId;
    };

    // The agent the host's route builds: continued in the conversation, for the person.
    $this->agent = fn (string $id, ?User $as = null): AskingAgent => (new AskingAgent($as ?? $this->user, $this->team))->continue($id, as: $as ?? $this->user);

    // One answered tool part carrying an ElicitResult; approved agrees with it unless given.
    $this->answer = fn (?array $result, string $id = 'call_1', ?bool $approved = null, string $tool = 'asking-draft'): array => [
        'type' => 'tool-'.$tool,
        'toolCallId' => $id,
        'state' => 'approval-responded',
        'approval' => ['id' => $id, 'approved' => $approved ?? ($result['action'] ?? null) === 'accept'],
        ...($result === null ? [] : ['elicitation' => $result]),
    ];

    $this->accept = fn (array $content, string $id = 'call_1', string $tool = 'asking-draft'): array => ($this->answer)(['action' => 'accept', 'content' => $content], $id, tool: $tool);

    $this->body = fn (array $parts): array => ['messages' => [['id' => 'msg-a1', 'role' => 'assistant', 'parts' => $parts]]];

    // ChatRequest::from() over a JSON body, as the given user, optionally as a Precognition request.
    $this->read = function (array $body, AskingAgent $agent, ?User $as = null, bool $precognition = false): ChatRequest {
        $request = Request::create('/assistant', 'POST', server: ['CONTENT_TYPE' => 'application/json', ...($precognition ? ['HTTP_PRECOGNITION' => 'true'] : [])], content: json_encode($body, JSON_THROW_ON_ERROR));
        $request->setUserResolver(fn (): User => $as ?? $this->user);

        return ChatRequest::from($request, $agent);
    };

    // What respond() answers without running the turn, and whether it ran it.
    $this->respond = function (ChatRequest $chat): array {
        $ran = false;
        $response = $chat->respond(function () use (&$ran) {
            $ran = true;

            return response()->noContent();
        });

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true), $response->headers, $ran];
    };

    $this->key = fn (string $id, string $call = 'call_1'): string => ApprovalClaims::PREFIX.hash('sha256', $id."\n".$call);
    $this->waits = fn (string $id, string $call = 'call_1'): bool => Cache::has(($this->key)($id, $call));
    $this->burned = fn (string $id, string $call = 'call_1'): bool => ! Cache::has(($this->key)($id, $call)) && Cache::has(($this->key)($id, $call).':claimed');
    $this->reservable = fn (string $id, array $calls = ['call_1']): bool => app(ApprovalClaims::class)->reserve($id, $calls) !== null;

    $this->valid = ['title' => 'Launch notes', 'body' => 'What shipped this week.', 'status' => 'draft'];
});

afterEach(function () {
    ignore_user_abort(false);
});

/**
 * Each decision's action and result, by id.
 *
 * @return array<string, array{0: string, 1: ?string}>
 */
function formDecisions(ChatRequest $chat): array
{
    return array_map(fn (Decision $decision): array => [$decision->action, $decision->result], $chat->decisions()?->all() ?? []);
}

describe('an accept', function () {
    it('approves the call, hands the resume the verified form and the values, and reserves the turn without spending its claim', function () {
        $id = ($this->pause)();

        $chat = ($this->read)(($this->body)([($this->accept)([...$this->valid, 'team_id' => 9, 'excerpt' => ''])]), ($this->agent)($id));
        $answer = app(PendingCards::class)->answered('call_1');

        expect(formDecisions($chat))->toBe(['call_1' => ['approve', null], '*' => ['reject', trans('agentic-actions::model.declined')]])
            ->and($chat->messageId())->toBe('msg-a1')
            ->and($answer['form'])->toBeInstanceOf(Form::class)
            ->and($answer['form']->fields)->toBe(['title', 'body', 'status'])
            ->and($answer['values'])->toBe($this->valid)
            ->and(($this->waits)($id))->toBeTrue()
            ->and(($this->reservable)($id))->toBeFalse()
            ->and(AskingDraft::$handled)->toBeNull();
    });

    it('answers content its form refuses with 422, its errors by field, and reserves, spends and stores nothing', function (array $content, array $fields) {
        $id = ($this->pause)();

        $chat = ($this->read)(($this->body)([($this->accept)($content)]), ($this->agent)($id));
        [$status, $json, , $ran] = ($this->respond)($chat);

        expect($status)->toBe(422)
            ->and($json['message'])->toBe(trans('agentic-actions::ask.invalid'))
            ->and(array_keys($json['errors']))->toBe($fields)
            ->and($ran)->toBeFalse()
            ->and($chat->decisions())->toBeNull()
            ->and(app(PendingCards::class)->answered('call_1'))->toBeNull()
            ->and(($this->waits)($id))->toBeTrue()
            ->and(($this->reservable)($id))->toBeTrue();
    })->with([
        'a wrong type' => [['title' => 'Launch notes', 'body' => ['x'], 'status' => 'draft'], ['body']],
        'a missing required field' => [['title' => 'Launch notes', 'status' => 'draft'], ['body']],
        'a value outside the choices' => [['title' => 'Launch notes', 'body' => 'x', 'status' => 'archived'], ['status']],
        'too long' => [['title' => 'Launch notes', 'body' => str_repeat('b', 5001), 'status' => 'draft'], ['body']],
        'an empty object' => [[], ['title', 'body', 'status']],
    ]);

    it('answers with the action\'s own messages in the request\'s locale', function () {
        $id = ($this->pause)();
        app('translator')->addLines(['validation.required' => 'حقل :attribute مطلوب.'], 'ar');

        $answer = ($this->body)([($this->accept)(['title' => 'Launch notes', 'status' => 'draft'])]);
        $agent = fn (string $locale): AskingAgent => (new AskingAgent($this->user, $this->team, $locale))->continue($id, as: $this->user);

        [, $english] = ($this->respond)(($this->read)($answer, $agent('en')));
        [, $arabic] = ($this->respond)(($this->read)($answer, $agent('ar')));

        expect($english['errors'])->toBe(['body' => ['The body field is required.']])
            ->and($arabic['errors'])->toBe(['body' => ['حقل body مطلوب.']])
            ->and(app()->getLocale())->toBe('en');
    });

    it('answers a date the action\'s own rules refuse with 422, and a later one then goes on', function () {
        $this->travelTo('2026-09-20 12:00:00');
        $id = ($this->pause)([['asking-rules', ['when' => '2026-09-19']]]);
        $answer = fn (string $when): array => ($this->body)([($this->accept)(['when' => $when], tool: 'asking-rules')]);

        [$status, $json] = ($this->respond)(($this->read)($answer('2026-09-01'), ($this->agent)($id)));

        expect($status)->toBe(422)
            ->and(array_keys($json['errors']))->toBe(['when'])
            ->and(formDecisions(($this->read)($answer('2026-10-01'), ($this->agent)($id)))['call_1'])->toBe(['approve', null]);
    });

    it('is no answer once the call\'s stored arguments pass on their own, so nothing matches: 409', function () {
        $this->travelTo('2026-09-20 12:00:00');
        $id = ($this->pause)([['asking-rules', ['when' => '2026-09-19']]]);

        // A rule that flipped between the pause and the answer: the model's own date would now run, unseen.
        $this->travelTo('2026-09-10 12:00:00');

        [$status, $json, , $ran] = ($this->respond)(($this->read)(($this->body)([($this->accept)(['when' => '2026-10-01'], tool: 'asking-rules')]), ($this->agent)($id)));

        expect($status)->toBe(409)
            ->and($json)->toBe(['message' => trans('agentic-actions::stream.stale')])
            ->and($ran)->toBeFalse()
            ->and(($this->waits)($id))->toBeTrue()
            ->and(AskingRules::$handled)->toBeNull();
    });
});

describe('a decline, a cancel and any other answer', function () {
    it('declines, naming the form\'s fields, or cancels, and spends the claim', function (string $action, string $line) {
        $id = ($this->pause)();

        $chat = ($this->read)(($this->body)([($this->answer)(['action' => $action, 'content' => ['body' => 'CANARY']])]), ($this->agent)($id));

        expect(formDecisions($chat)['call_1'])->toBe(['reject', $line])
            ->and(json_encode(formDecisions($chat)))->not->toContain('CANARY')
            ->and(($this->burned)($id))->toBeTrue()
            ->and(app(PendingCards::class)->answered('call_1'))->toBeNull();
    })->with([
        'decline' => ['decline', 'The person declined to fill in title, body, status, so nothing ran. Do not ask again unless they ask.'],
        'cancel' => ['cancel', 'The person closed the form without answering, so nothing ran. Offer it again only if it still matters.'],
    ]);

    it('declines a form\'s call answered without an agreeing ElicitResult, never reading a reason, and spends its claim', function (?array $result, bool $approved) {
        $id = ($this->pause)();
        $part = ($this->answer)($result, approved: $approved);
        $part['approval']['reason'] = 'CANARY-REASON';

        $chat = ($this->read)(($this->body)([$part]), ($this->agent)($id));

        expect(formDecisions($chat)['call_1'])->toBe(['reject', trans('agentic-actions::model.declined')])
            ->and(($this->burned)($id))->toBeTrue()
            ->and(app(PendingCards::class)->answered('call_1'))->toBeNull();
    })->with([
        'a bare approval' => [null, true],
        'a bare decline' => [null, false],
        'an accept that is not approved' => [['action' => 'accept', 'content' => ['body' => 'x']], false],
        'a decline that is approved' => [['action' => 'decline'], true],
        'a cancel that is approved' => [['action' => 'cancel'], true],
        'an action outside the three' => [['action' => 'submit', 'content' => ['body' => 'x']], true],
        'an action that is not a string' => [['action' => true], true],
        'content that is a list' => [['action' => 'accept', 'content' => ['x', 'y']], true],
        'content that is a string' => [['action' => 'accept', 'content' => 'body=x'], true],
        'an elicitation that is a string' => [['accept'], true],
    ]);

    it('runs nothing for a bare approval of a form\'s call, even once the model\'s own arguments would pass', function () {
        $this->travelTo('2026-09-20 12:00:00');
        $id = ($this->pause)([['asking-rules', ['when' => '2026-09-19']]]);
        $this->travelTo('2026-09-10 12:00:00');
        $agent = ($this->agent)($id);

        $agent->prompt(($this->read)(($this->body)([($this->answer)(null, approved: true, tool: 'asking-rules')]), $agent));

        expect(AskingRules::$handled)->toBeNull()
            ->and(app(ConversationStore::class)->pendingApprovalsFor($id))->toBe([])
            ->and(json_encode($this->gateway->sent, JSON_THROW_ON_ERROR))->toContain(trans('agentic-actions::model.declined'));
    });
});

describe('a card and a form', function () {
    beforeEach(function () {
        $this->post = $this->user->posts()->create(['title' => 'Old notes', 'body' => 'x', 'status' => 'draft']);
        $this->both = fn (): string => ($this->pause)([['asking-delete', ['post' => $this->post->id]], ['asking-draft', ['title' => 'Launch notes']]]);
    });

    it('gives both decisions for a body that answers a card and a form of one step together', function () {
        $id = ($this->both)();
        $agent = ($this->agent)($id);
        $card = ['type' => 'tool-asking-delete', 'toolCallId' => 'call_1', 'state' => 'approval-responded', 'approval' => ['id' => 'call_1', 'approved' => true]];

        $chat = ($this->read)(($this->body)([$card, ($this->accept)($this->valid, 'call_2')]), $agent);

        expect(formDecisions($chat))->toBe([
            'call_1' => ['approve', null],
            'call_2' => ['approve', null],
            '*' => ['reject', trans('agentic-actions::model.declined')],
        ]);

        $agent->prompt($chat);

        expect(AskingDelete::$handled)->toBeTrue()
            ->and(AskingDraft::$handled)->toBe($this->valid);
    });
});

describe('a Precognition request', function () {
    it('checks a valid answer: 204 with both headers, and reserves, spends, decides and stores nothing', function () {
        $id = ($this->pause)();

        $chat = ($this->read)(($this->body)([($this->accept)($this->valid)]), ($this->agent)($id), precognition: true);
        [$status, , $headers, $ran] = ($this->respond)($chat);

        expect($status)->toBe(204)
            ->and($headers->get('Precognition'))->toBe('true')
            ->and($headers->get('Precognition-Success'))->toBe('true')
            ->and($ran)->toBeFalse()
            ->and($chat->decisions())->toBeNull()
            ->and($chat->messageId())->toBeNull()
            ->and(app(PendingCards::class)->answered('call_1'))->toBeNull()
            ->and(($this->waits)($id))->toBeTrue()
            ->and(($this->reservable)($id))->toBeTrue();
    });

    it('answers an invalid answer with 422 and the Precognition header', function () {
        $id = ($this->pause)();

        [$status, $json, $headers] = ($this->respond)(($this->read)(($this->body)([($this->accept)(['title' => 'Launch notes'])]), ($this->agent)($id), precognition: true));

        expect($status)->toBe(422)
            ->and(array_keys($json['errors']))->toBe(['body', 'status'])
            ->and($headers->get('Precognition'))->toBe('true')
            ->and($headers->has('Precognition-Success'))->toBeFalse();
    });

    it('spends nothing for a decline it checks', function () {
        $id = ($this->pause)();

        [$status] = ($this->respond)(($this->read)(($this->body)([($this->answer)(['action' => 'decline'])]), ($this->agent)($id), precognition: true));

        expect($status)->toBe(204)
            ->and(($this->waits)($id))->toBeTrue();
    });

    it('checks words with 204 and reserves nothing', function () {
        $id = ($this->pause)();
        $words = ['messages' => [['id' => 'm2', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Never mind.']]]]];

        $chat = ($this->read)($words, ($this->agent)($id), precognition: true);

        expect(($this->respond)($chat)[0])->toBe(204)
            ->and($chat->message())->toBeNull()
            ->and(($this->reservable)($id))->toBeTrue();
    });

    it('answers 409 when nothing matches', function () {
        $id = ($this->pause)();

        expect(($this->respond)(($this->read)(($this->body)([($this->accept)($this->valid, 'call_9')]), ($this->agent)($id), precognition: true))[0])->toBe(409);
    });
});

describe('who may answer', function () {
    it('reads another person\'s answer as stale and runs nothing', function () {
        $id = ($this->pause)();
        $other = User::factory()->create();

        expect(($this->respond)(($this->read)(($this->body)([($this->accept)($this->valid)]), ($this->agent)($id, $other), $other))[0])->toBe(409)
            ->and(($this->respond)(($this->read)(($this->body)([($this->accept)($this->valid)]), ($this->agent)($id), $other))[0])->toBe(409)
            ->and(($this->waits)($id))->toBeTrue()
            ->and(app(PendingCards::class)->answered('call_1'))->toBeNull();
    });
});
