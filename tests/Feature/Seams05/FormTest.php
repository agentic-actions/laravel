<?php

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Approvals\ApprovalClaims;
use AgenticActions\Elicitation\Form;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Schema\AdvertisedSchema;
use Tests\Fixtures\Elicitation\AskingChoices;
use Tests\Fixtures\Elicitation\AskingDraft;
use Tests\Fixtures\Elicitation\AskingNested;
use Tests\Fixtures\Elicitation\AskingSchedule;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * Form: MCP's form params built on the server, the part and the refused row the stream carries, what a claim binds,
 * and how an answer becomes the values the action runs with. Built here straight from Form::build(), as
 * Runner::preview() builds it, with the refused call's errors.
 */

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Ada']);
    $this->context = ActionContext::agent($this->user)->forAction('asking-draft');
});

/**
 * Build the form for a class's call, from a fresh instance, as the preview does.
 *
 * @param  class-string<Action>  $class
 * @param  array<string, mixed>  $arguments
 * @param  array<string, list<string>>  $errors
 */
function seams05Form(string $class, array $arguments, array $errors, ActionContext $context): ?Form
{
    $action = app($class);

    return Form::build(ClassExposure::of($class), $action, $context, app(AdvertisedSchema::class)->node($action, $context), $arguments, $errors);
}

describe('build()', function () {
    it('asks the refused fields and the confirmed ones, in the schema\'s order, the confirmed one opening on the model\'s value', function () {
        $form = seams05Form(AskingDraft::class, ['title' => 'Launch notes', 'excerpt' => 'CANARY-EXCERPT'], [
            'body' => ['The body field is required.'],
            'status' => ['The status field is required.'],
        ], $this->context);

        expect($form->fields)->toBe(['title', 'body', 'status'])
            ->and($form->message)->toBe('A few details for your post.')
            ->and($form->schema)->toBe([
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'title' => 'Title', 'minLength' => 1, 'maxLength' => 120, 'default' => 'Launch notes'],
                    'body' => ['type' => 'string', 'title' => 'Body', 'maxLength' => 5000, 'x-agentic-actions' => ['widget' => 'textarea']],
                    'status' => ['type' => 'string', 'title' => 'Status', 'oneOf' => [['const' => 'draft', 'title' => 'Draft'], ['const' => 'published', 'title' => 'Published']]],
                ],
                'required' => ['title', 'body', 'status'],
            ])
            ->and($form->errors)->toBe(['body' => ['The body field is required.'], 'status' => ['The status field is required.']])
            ->and($form->values)->toBe([])
            ->and($form->fingerprint)->toBe(ApprovalClaims::fingerprint(['title' => 'Launch notes', 'excerpt' => 'CANARY-EXCERPT']))
            ->and(json_encode($form->params()))->not->toContain('CANARY');
    });

    it('reads a nullable field as optional, and groups a list\'s errors under its field', function () {
        $form = seams05Form(AskingSchedule::class, [], ['when' => ['Required.'], 'tags.0' => ['Not a tag.'], 'tags.1' => ['Not a tag either.']], $this->context);

        expect($form->fields)->toBe(['when', 'tags'])
            ->and($form->schema['required'])->toBe(['when'])
            ->and($form->errors)->toBe(['when' => ['Required.'], 'tags' => ['Not a tag.', 'Not a tag either.']]);

        $excerpt = seams05Form(AskingDraft::class, ['title' => 'x', 'body' => 'x', 'status' => 'draft'], ['excerpt' => ['Too long.']], $this->context);

        expect($excerpt->fields)->toBe(['title', 'excerpt'])
            ->and($excerpt->schema['required'])->toBe(['title']);
    });

    it('gives no form when a refused field is one a form cannot hold, or one the agent was not offered', function () {
        expect(seams05Form(AskingNested::class, [], ['title' => ['Required.'], 'address' => ['Required.']], $this->context))->toBeNull()
            ->and(seams05Form(AskingNested::class, [], ['address.street' => ['Required.']], $this->context))->toBeNull()
            ->and(seams05Form(AskingDraft::class, [], ['body' => ['Required.'], 'user_id' => ['Required.']], $this->context))->toBeNull();
    });

    it('builds the choices ask() reads for the context\'s tenant, opening on the actor as a const string', function () {
        $this->useTeamTenancy();
        $team = Team::factory()->create();
        $grace = User::factory()->create(['name' => 'Grace']);
        $team->users()->attach([$this->user->id, $grace->id]);

        $form = seams05Form(AskingChoices::class, [], ['owner' => ['Required.']], ActionContext::agent($this->user, $team));

        expect($form->schema['properties']['owner'])->toBe([
            'type' => 'string',
            'title' => 'Owner',
            'oneOf' => [['const' => (string) $this->user->id, 'title' => 'Ada'], ['const' => (string) $grace->id, 'title' => 'Grace']],
            'default' => (string) $this->user->id,
        ])->and($form->values)->toBe(['owner' => [(string) $this->user->id => $this->user->id, (string) $grace->id => $grace->id]]);
    });
});

describe('the wire', function () {
    beforeEach(function () {
        config(['app.name' => 'Blog']);

        $this->form = seams05Form(AskingDraft::class, ['title' => 'Launch notes'], ['body' => ['Required.'], 'status' => ['Required.']], $this->context);
    });

    it('sends MCP\'s form params, with the widget hint', function () {
        expect($this->form->params())->toBe(['mode' => 'form', 'message' => 'A few details for your post.', 'requestedSchema' => $this->form->schema])
            ->and($this->form->params()['requestedSchema']['properties']['body']['x-agentic-actions'])->toBe(['widget' => 'textarea']);
    });

    it('leaves the widget hint out for MCP, and opens a re-ask on the accepted values with each refused field\'s messages', function () {
        $params = $this->form->params(false, ['title' => 'Kept', 'body' => 'Refused', 'status' => 'published'], ['body' => ['Too short.', 'Say more.']]);

        expect($params['requestedSchema']['properties'])->toBe([
            'title' => ['type' => 'string', 'title' => 'Title', 'minLength' => 1, 'maxLength' => 120, 'default' => 'Kept'],
            'body' => ['type' => 'string', 'title' => 'Body', 'maxLength' => 5000, 'description' => 'Too short. Say more.'],
            'status' => ['type' => 'string', 'title' => 'Status', 'oneOf' => [['const' => 'draft', 'title' => 'Draft'], ['const' => 'published', 'title' => 'Published']], 'default' => 'published'],
        ])->and($this->form->params(false))->toBe(['mode' => 'form', 'message' => 'A few details for your post.', 'requestedSchema' => [
            ...$this->form->schema,
            'properties' => [...$this->form->schema['properties'], 'body' => ['type' => 'string', 'title' => 'Body', 'maxLength' => 5000]],
        ]]);
    });

    it('opens a re-ask on a numeric choice as its const', function () {
        $this->useTeamTenancy();
        $team = Team::factory()->create();
        $grace = User::factory()->create(['name' => 'Grace']);
        $team->users()->attach([$this->user->id, $grace->id]);

        $form = seams05Form(AskingChoices::class, [], ['owner' => ['Required.'], 'ids' => ['Required.']], ActionContext::agent($this->user, $team));
        $properties = $form->params(false, ['owner' => $grace->id, 'ids' => [3, 1]])['requestedSchema']['properties'];

        expect($properties['owner']['default'])->toBe((string) $grace->id)
            ->and($properties['ids']['default'])->toBe(['3', '1']);
    });

    it('builds the data-elicitation part, whose source line names the app', function () {
        expect($this->form->part('call_1'))->toBe([
            'type' => 'data-elicitation',
            'id' => 'elicitation:call_1',
            'data' => [
                'action' => 'asking-draft',
                'params' => $this->form->params(),
                'labels' => ['source' => 'Asked by Blog', 'submit' => 'Submit', 'decline' => 'Decline', 'cancel' => 'Not now'],
            ],
        ]);
    });

    it('builds the labels in the context\'s locale', function () {
        $arabic = seams05Form(AskingDraft::class, [], ['title' => ['x']], ActionContext::agent($this->user, locale: 'ar'));

        expect($arabic->part('call_1')['data']['labels'])->toBe(['source' => 'طلب من Blog', 'submit' => 'إرسال', 'decline' => 'رفض', 'cancel' => 'ليس الآن']);
    });

    it('reads the package\'s sentence when ask() gives none', function () {
        $form = seams05Form(AskingSchedule::class, [], ['when' => ['Required.']], $this->context);

        expect($form->message)->toBe('A few details are needed before this can go ahead.');
    });

    it('builds the refused row, labelled with the message', function () {
        expect($this->form->refusedRow('call_1'))->toBe([
            'type' => 'data-action',
            'id' => 'a:call_1',
            'data' => ['action' => 'asking-draft', 'label' => 'A few details for your post.', 'status' => 'refused', 'effect' => 'write', 'note' => trans('agentic-actions::activity.refused')],
        ]);
    });
});

describe('binding()', function () {
    it('binds "form", the action, the person, the tenant, the conversation, the call and the arguments\' fingerprint', function () {
        $form = seams05Form(AskingDraft::class, ['title' => 'Launch notes'], ['body' => ['Required.']], $this->context);

        expect($form->binding('conversation-1', 'call_1'))->toBe(implode("\n", [
            'form', 'asking-draft', $this->context->actorKey(), '-', 'conversation-1', 'call_1', ApprovalClaims::fingerprint(['title' => 'Launch notes']),
        ]));
    });
});

describe('an answer', function () {
    beforeEach(function () {
        $this->form = new Form(ClassExposure::of(AskingDraft::class), $this->context, 'Why.', ['type' => 'object', 'properties' => []], ['body', 'share', 'tags', 'size', 'ids'], [
            'size' => ['12' => 12, '24' => 24],
            'ids' => ['1' => 1, '2' => 2],
        ], [], 'fingerprint');
    });

    it('keeps the form\'s keys only, drops null and \'\', keeps false and [], and maps consts back', function () {
        expect($this->form->content([
            'body' => 'What shipped.',
            'share' => false,
            'tags' => [],
            'size' => '12',
            'ids' => ['2', '1'],
            'team_id' => 99,
            'title' => 'CANARY',
        ]))->toBe(['body' => 'What shipped.', 'share' => false, 'tags' => [], 'size' => 12, 'ids' => [2, 1]])
            ->and($this->form->content(['body' => '', 'share' => null, 'size' => '99']))->toBe(['size' => '99']);
    });

    it('drops an answer of only whitespace, as it drops \'\', which is what the web\'s middleware makes of it in the app', function () {
        expect($this->form->content(['body' => " \t ", 'size' => "\u{00A0}"]))->toBe([])
            ->and($this->form->content(['body' => ' a ']))->toBe(['body' => ' a ']);
    });

    it('merges the person\'s values over the model\'s arguments, never keeping the model\'s value for a field left empty', function () {
        expect($this->form->merge(['title' => 'Launch notes', 'body' => 'MODEL-BODY', 'share' => true], ['share' => false]))
            ->toBe(['share' => false, 'title' => 'Launch notes'])
            ->and($this->form->merge(['title' => 'Launch notes'], ['body' => 'Mine', 'title' => 'CANARY']))->toBe(['body' => 'Mine', 'title' => 'Launch notes']);
    });

    it('names the fields given a value, in the form\'s order, and never a value', function () {
        $sentence = $this->form->filled(['size' => 12, 'body' => 'CANARY-BODY', 'share' => false]);

        expect($sentence)->toBe('The person filled in: body, share, size.')
            ->and($sentence)->not->toContain('CANARY');
    });
});
