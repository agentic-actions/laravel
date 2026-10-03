<?php

use AgenticActions\Console\Checks;
use AgenticActions\Console\Finding;
use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Discovery\Snapshot;
use AgenticActions\Exposure\ClassExposure;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Approvals\ConfirmingAgent;
use Tests\Fixtures\Approvals\ForgetfulStore;
use Tests\Fixtures\Approvals\StatelessAgent;
use Tests\Fixtures\Install\DetachedToken;
use Tests\Fixtures\Views\PostStats;
use Tests\Fixtures\Views\ViewsAgent;

/*
 * The Tables row warns when a feature in use lacks its tables: agents that store their conversations in laravel/ai's
 * database store, a published agentic_conversations migration, an agent offered an action that shows a table, and MCP
 * on the sanctum guard. Each message ends with
 * "run php artisan actions:install" and the flags that publish them. The "empty" connection is a database with no
 * tables at all.
 */

beforeEach(function () {
    config(['database.connections.empty' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

    $this->migration = database_path('migrations/2026_09_26_000000_create_agentic_conversations_table.php');
});

afterEach(function () {
    Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

    File::delete([$this->migration, Snapshot::path()]);
});

/**
 * The Tables row's findings for exactly these classes, as [level, message] pairs.
 *
 * @param  list<class-string>  $classes
 * @return list<array{0: string, 1: string}>
 */
function tablesFindings(array $classes): array
{
    config(['agentic-actions.discovery.paths' => [], 'agentic-actions.discovery.classes' => $classes]);

    ClassExposure::flush();
    app()->forgetInstance(ActionRegistry::class);

    return array_values(array_map(
        fn (Finding $finding): array => [$finding->level, $finding->message],
        array_filter(app(Checks::class)->run(), fn (Finding $finding): bool => $finding->row === 'Tables'),
    ));
}

describe('laravel/ai\'s tables', function () {
    beforeEach(function () {
        $this->skipUnlessAi();
    });

    it('passes agents that store their conversations while the tables exist', function () {
        expect(tablesFindings([ConfirmingAgent::class]))->toBe([]);
    });

    it('warns when the database lacks them, naming the agents', function () {
        config(['ai.conversations.connection' => 'empty']);

        expect(tablesFindings([ConfirmingAgent::class, StatelessAgent::class]))->toBe([[
            'warn',
            'The agents ['.ConfirmingAgent::class.'] store their conversations in laravel/ai\'s database, but the database has no [agent_conversations, agent_conversation_messages] tables: run php artisan actions:install --copilot',
        ]]);
    });

    it('reads nothing for an agent that keeps no conversations, or a store of the app\'s own', function () {
        config(['ai.conversations.connection' => 'empty']);

        expect(tablesFindings([StatelessAgent::class]))->toBe([]);

        app()->instance(ConversationStore::class, new ForgetfulStore);

        expect(tablesFindings([ConfirmingAgent::class]))->toBe([]);
    });

    it('reports nothing when the database cannot be read', function () {
        config([
            'database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent/agentic-actions.sqlite', 'prefix' => ''],
            'ai.conversations.connection' => 'broken',
        ]);

        expect(tablesFindings([ConfirmingAgent::class]))->toBe([]);
    });
});

describe('the conversation store', function () {
    it('warns when its migration is published but its table is missing', function () {
        File::put($this->migration, '<?php // published');
        config(['ai.conversations.connection' => 'empty']);

        expect(tablesFindings([CreateNote::class]))->toBe([[
            'warn',
            'Actions::conversation() keeps a conversation per tenant, but the database has no [agentic_conversations] table: run php artisan actions:install --copilot --tenancy',
        ]]);
    });

    it('passes once the table exists, and reads nothing while the migration is not published', function () {
        $this->skipUnlessAi();

        File::put($this->migration, '<?php // published');

        expect(tablesFindings([CreateNote::class]))->toBe([]);

        File::delete($this->migration);
        config(['ai.conversations.connection' => 'empty']);

        expect(tablesFindings([CreateNote::class]))->toBe([]);
    });
});

describe('Sanctum\'s tokens', function () {
    it('warns when MCP is served on the sanctum guard without the token table', function () {
        Sanctum::usePersonalAccessTokenModel(DetachedToken::class);

        expect(tablesFindings([CreateNote::class]))->toBe([[
            'warn',
            'MCP clients sign in with Sanctum tokens, but the database has no [personal_access_tokens] table: run php artisan actions:install --mcp',
        ]]);
    });

    it('passes with the table, and reads nothing with MCP off or on another guard', function (array $config, bool $detached) {
        config($config);

        if ($detached) {
            Sanctum::usePersonalAccessTokenModel(DetachedToken::class);
        }

        expect(tablesFindings([CreateNote::class]))->toBe([]);
    })->with([
        'the table exists' => [[], false],
        'MCP is off' => [['agentic-actions.surfaces.mcp' => false], true],
        'a session guard' => [['agentic-actions.mcp.middleware' => ['auth:web']], true],
    ]);
});

describe('the tables agents show', function () {
    beforeEach(function () {
        $this->skipUnlessAi();
    });

    it('warns when an agent is offered a table action and the database lacks agentic_views', function () {
        config(['ai.conversations.connection' => 'empty']);

        expect(tablesFindings([ViewsAgent::class, PostStats::class]))->toContain([
            'warn',
            'Agents show the tables of [post-stats], kept with each conversation, but the database has no [agentic_views] table: run php artisan actions:install --copilot',
        ]);
    });

    it('passes once the table exists, and reads nothing while no agent is offered a table action', function () {
        expect(tablesFindings([ViewsAgent::class, PostStats::class]))->toBe([]);

        config(['ai.conversations.connection' => 'empty']);

        expect(tablesFindings([PostStats::class]))->toBe([]);
    });
});
