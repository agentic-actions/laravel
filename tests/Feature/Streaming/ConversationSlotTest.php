<?php

use AgenticActions\AgenticActionsServiceProvider;
use AgenticActions\Facades\Actions;
use AgenticActions\Streaming\AgenticConversation;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;
use Tests\Fixtures\Approvals\ConfirmingAgent;
use Tests\Fixtures\Approvals\StatelessAgent;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * Actions::conversation() keeps one laravel/ai conversation per participant, tenant and agent class, in the
 * agentic_conversations table. Its key is what the server passes, so nobody reaches another person's or another
 * tenant's conversation through it.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
});

it('finds nothing until open() starts the conversation in laravel/ai\'s store, then always the same one', function () {
    $slot = Actions::conversation(ConfirmingAgent::class, $this->user, $this->team);

    expect($slot->id())->toBeNull();

    $id = $slot->open();
    $conversation = Conversation::query()->findOrFail($id);

    expect($slot->id())->toBe($id)
        ->and($slot->open())->toBe($id)
        ->and($conversation->title)->toBe('Confirming Agent')
        ->and($conversation->participant_type)->toBe($this->user->getMorphClass())
        ->and((int) $conversation->participant_id)->toBe($this->user->id)
        ->and(AgenticConversation::query()->sole()->only(['participant_type', 'participant_id', 'tenant_type', 'tenant_id', 'agent', 'conversation_id']))->toEqual([
            'participant_type' => $this->user->getMorphClass(),
            'participant_id' => $this->user->id,
            'tenant_type' => $this->team->getMorphClass(),
            'tenant_id' => $this->team->id,
            'agent' => ConfirmingAgent::class,
            'conversation_id' => $id,
        ]);
});

it('keeps one conversation per participant, tenant and agent class, each owned by its participant', function () {
    $other = User::factory()->create();
    $otherTeam = Team::factory()->create();

    $slots = [
        'this person in this team' => Actions::conversation(ConfirmingAgent::class, $this->user, $this->team),
        'another person in this team' => Actions::conversation(ConfirmingAgent::class, $other, $this->team),
        'this person in another team' => Actions::conversation(ConfirmingAgent::class, $this->user, $otherTeam),
        'this person with no tenant' => Actions::conversation(ConfirmingAgent::class, $this->user),
        'this person with another agent' => Actions::conversation(StatelessAgent::class, $this->user, $this->team),
    ];

    $ids = array_map(fn ($slot): string => $slot->open(), $slots);
    $store = app(ConversationStore::class);

    expect(array_unique($ids))->toHaveCount(5)
        ->and($store->conversationBelongsTo($ids['another person in this team'], $other->getMorphClass(), $other->id))->toBeTrue()
        ->and($store->conversationBelongsTo($ids['another person in this team'], $this->user->getMorphClass(), $this->user->id))->toBeFalse()
        ->and(Actions::conversation(ConfirmingAgent::class, $this->user, $this->team)->id())->toBe($ids['this person in this team'])
        ->and(Actions::conversation(ConfirmingAgent::class, $this->user)->id())->toBe($ids['this person with no tenant']);
});

it('tells a tenant from a participant of another type with the same key', function () {
    $user = User::factory()->create(['id' => $this->team->id + 100]);
    $team = Team::factory()->create(['id' => $user->id]);

    $asTeam = Actions::conversation(ConfirmingAgent::class, $this->user, $team)->open();

    expect(Actions::conversation(ConfirmingAgent::class, $this->user, $user)->id())->toBeNull()
        ->and(Actions::conversation(ConfirmingAgent::class, $this->user, $team)->id())->toBe($asTeam);
});

it('keys the participant by its morph alias, so a model of another type with the same key is someone else', function () {
    Relation::morphMap(['person' => User::class, 'team' => Team::class]);

    try {
        $namesake = Team::factory()->create(['id' => $this->user->id + 100]);
        $person = User::factory()->create(['id' => $namesake->id]);
        $id = Actions::conversation(ConfirmingAgent::class, $person)->open();

        expect(Actions::conversation(ConfirmingAgent::class, $namesake)->id())->toBeNull()
            ->and(Actions::conversation(ConfirmingAgent::class, $person)->id())->toBe($id)
            ->and(AgenticConversation::query()->sole()->participant_type)->toBe('person');
    } finally {
        Relation::morphMap([], false);
    }
});

/**
 * Open the slot as a first turn does while another first turn for the same key opens it too: the other one lands
 * after this turn's id() found nothing and it stored its own conversation, before it writes its row. Returns both ids.
 *
 * @return array{0: string, 1: string} this turn's id, then the other turn's
 */
function openAgainstAnotherTurn(Closure $slot): array
{
    $other = null;

    DB::connection((new AgenticConversation)->getConnectionName())->listen(function (QueryExecuted $query) use (&$other, $slot): void {
        if ($other === null && str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'agent_conversations')) {
            $other = '';
            $other = $slot()->open();
        }
    });

    return [$slot()->open(), (string) $other];
}

it('continues the conversation a concurrent first turn opened, and keeps no empty one of its own', function () {
    [$id, $other] = openAgainstAnotherTurn(fn () => Actions::conversation(ConfirmingAgent::class, $this->user, $this->team));

    expect($id)->toBe($other)
        ->and(AgenticConversation::query()->pluck('conversation_id')->all())->toBe([$other])
        ->and(Conversation::query()->pluck('id')->all())->toBe([$other]);
});

it('takes the agent itself or its class, and a title for the new conversation', function () {
    $id = Actions::conversation(new ConfirmingAgent($this->user, $this->team), $this->user, $this->team)->open('Acme board');

    expect(Actions::conversation(ConfirmingAgent::class, $this->user, $this->team)->id())->toBe($id)
        ->and(Conversation::query()->findOrFail($id)->title)->toBe('Acme board');
});

it('refuses a participant or a tenant that is not saved', function (Closure $slot) {
    expect(fn () => $slot($this))->toThrow(LogicException::class, 'A conversation belongs to a saved participant and a saved tenant.');
})->with([
    'participant' => [fn ($test) => Actions::conversation(ConfirmingAgent::class, User::factory()->make(), $test->team)],
    'tenant' => [fn ($test) => Actions::conversation(ConfirmingAgent::class, $test->user, Team::factory()->make())],
]);

it('references laravel/ai\'s conversation, so deleting it deletes the row', function () {
    $keys = array_map(fn (array $key): array => Arr::only($key, ['columns', 'foreign_table', 'foreign_columns', 'on_delete']), Schema::connection((new AgenticConversation)->getConnectionName())->getForeignKeys('agentic_conversations'));

    expect($keys)->toBe([[
        'columns' => ['conversation_id'],
        'foreign_table' => 'agent_conversations',
        'foreign_columns' => ['id'],
        'on_delete' => 'cascade',
    ]]);
});

it('forgets a conversation deleted from laravel/ai\'s store where foreign keys hold, and opens a new one', function () {
    config([
        'database.connections.enforced' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
        'ai.conversations.connection' => 'enforced',
    ]);
    app()->forgetInstance(ConversationStore::class);

    $this->artisan('migrate', ['--database' => 'enforced', '--realpath' => true, '--path' => [
        dirname(__DIR__, 3).'/vendor/laravel/ai/database/migrations',
        dirname(__DIR__, 3).'/database/migrations',
    ]])->assertSuccessful();

    $slot = Actions::conversation(ConfirmingAgent::class, $this->user, $this->team);
    $id = $slot->open();

    Conversation::query()->whereKey($id)->delete();

    expect(AgenticConversation::query()->count())->toBe(0)
        ->and($slot->id())->toBeNull()
        ->and($slot->open())->not->toBe($id);
});

it('holds on MySQL and Postgres: a concurrent first turn continues the other\'s, and the row goes with its conversation', function () {
    if (DB::connection((new AgenticConversation)->getConnectionName())->getDriverName() === 'sqlite') {
        $this->markTestSkipped('The suite\'s SQLite runs without foreign keys; the tests above cover SQLite.');
    }

    [$id, $other] = openAgainstAnotherTurn(fn () => Actions::conversation(ConfirmingAgent::class, $this->user, $this->team));

    expect($id)->toBe($other)
        ->and(Conversation::query()->count())->toBe(1);

    Conversation::query()->whereKey($id)->delete();

    expect(AgenticConversation::query()->count())->toBe(0);
})->group('database');

it('publishes its migration only under the agentic-actions-migrations tag, and the OAuth table under its own', function () {
    $conversations = '2026_09_26_000001_create_agentic_conversations_table.php';
    $connections = '2026_09_27_000001_create_agentic_mcp_connections_table.php';

    expect(ServiceProvider::pathsToPublish(AgenticActionsServiceProvider::class, 'agentic-actions-migrations'))->toBe([
        dirname(__DIR__, 3)."/src/../database/migrations/{$conversations}" => database_path("migrations/{$conversations}"),
    ])->and(ServiceProvider::pathsToPublish(AgenticActionsServiceProvider::class, 'agentic-actions-oauth-migrations'))->toBe([
        dirname(__DIR__, 3)."/src/../database/migrations/{$connections}" => database_path("migrations/{$connections}"),
    ]);
});
