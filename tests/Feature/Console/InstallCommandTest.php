<?php

use AgenticActions\Discovery\Scanner;
use AgenticActions\Discovery\Snapshot;
use AgenticActions\Facades\Actions;
use AgenticActions\Support\PackageStatus;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;
use Tests\Fixtures\Install\PassportUser;
use Tests\Fixtures\Install\RecordingCommand;
use Tests\Fixtures\OAuth\Flow;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * actions:install publishes what the chosen features need, once: the config, laravel/ai's tables, the package's
 * conversation store and Sanctum's table (through install:api when Sanctum is missing). It prints the next steps the
 * app has not taken, and asks before it runs the migrations it published. install:api and migrate are stood in for,
 * so no test runs Composer or touches the suite's database.
 */

/**
 * Remove every file actions:install may have published into the Testbench skeleton.
 */
function forgetInstall(): void
{
    File::delete([config_path('agentic-actions.php'), config_path('ai.php')]);
    File::deleteDirectory(base_path('stubs'));

    foreach (['agent_conversations', 'agentic_conversations', 'agentic_views', 'personal_access_tokens', 'agentic_mcp_connections', 'widgets'] as $table) {
        File::delete(File::glob(database_path("migrations/*_create_{$table}_table.php")));
    }
}

/**
 * The published migrations of these tables, by table.
 *
 * @return array<string, int>
 */
function publishedMigrations(): array
{
    $counts = [];

    foreach (['agent_conversations', 'agentic_conversations', 'personal_access_tokens'] as $table) {
        $counts[$table] = count(File::glob(database_path("migrations/*_create_{$table}_table.php")));
    }

    return $counts;
}

/**
 * Stand in for install:api and migrate.
 */
function recordInstallCommands(): void
{
    RecordingCommand::$calls = [];

    $kernel = app(Kernel::class);
    $kernel->registerCommand(new RecordingCommand('install:api', '{--without-migration-prompt}'));
    $kernel->registerCommand(new RecordingCommand('migrate'));
}

/**
 * Put a migration of this table, one actions:install publishes, in database/migrations without running it.
 */
function unranMigration(string $table): void
{
    File::put(database_path("migrations/2026_01_01_000000_create_{$table}_table.php"), '<?php return new class extends Illuminate\Database\Migrations\Migration {};');
}

/**
 * How many copies of the connections migration database/migrations holds.
 */
function publishedConnections(): int
{
    return count(File::glob(database_path('migrations/*_create_agentic_mcp_connections_table.php')));
}

beforeEach(function () {
    forgetInstall();
    recordInstallCommands();
    File::delete(Snapshot::path());
});

afterEach(function () {
    forgetInstall();
    File::delete(Snapshot::path());
});

it('asks which features the app uses, and for the web routes publishes the config and prints the route line', function () {
    $this->artisan('actions:install')
        ->expectsQuestion('Which features does this app use?', ['web'])
        ->expectsOutputToContain('Setting up: web.')
        ->expectsOutputToContain("routes/web.php   Route::middleware('auth')->group(fn () => Actions::routes());")
        ->expectsOutputToContain('Each routes file needs: use AgenticActions\Facades\Actions;')
        ->expectsOutputToContain('php artisan actions:check --update')
        ->doesntExpectOutputToContain('make:agent Assistant')
        ->assertSuccessful();

    expect(config_path('agentic-actions.php'))->toBeFile()
        ->and(publishedMigrations())->toBe(['agent_conversations' => 0, 'agentic_conversations' => 0, 'personal_access_tokens' => 0])
        ->and(RecordingCommand::$calls)->toBe([]);
});

it('sets up the web routes alone without interaction and without flags', function () {
    $this->artisan('actions:install', ['--no-interaction' => true])
        ->expectsOutputToContain('Setting up: web.')
        ->assertSuccessful();

    expect(config_path('agentic-actions.php'))->toBeFile();
});

it('publishes laravel/ai\'s tables for a copilot, and runs the migrations once the person agrees', function (string $answer, array $migrated) {
    $this->skipUnlessAi();

    $this->artisan('actions:install', ['--copilot' => true])
        ->expectsOutputToContain("laravel/ai's conversation tables")
        ->expectsOutputToContain('php artisan make:agent Assistant, then the chat route of https://agentic-actions.com/copilot#the-server')
        ->expectsConfirmation('Would you like to run all pending database migrations?', $answer)
        ->assertSuccessful();

    expect(publishedMigrations())->toBe(['agent_conversations' => 1, 'agentic_conversations' => 0, 'personal_access_tokens' => 0])
        ->and(config_path('ai.php'))->toBeFile()
        ->and(array_keys(RecordingCommand::$calls))->toBe($migrated);
})->with([
    'yes' => ['yes', ['migrate']],
    'no' => ['no', []],
]);

it('publishes the conversation store for a copilot with tenants, after laravel/ai\'s tables it references', function () {
    $this->skipUnlessAi();

    $this->artisan('actions:install', ['--web' => true, '--copilot' => true, '--tenancy' => true, '--no-interaction' => true])
        ->expectsOutputToContain('The agentic_conversations table')
        ->expectsOutputToContain("routes/web.php   Route::middleware('auth')->group(fn () => Actions::routes(tenant: false));")
        ->expectsOutputToContain('config/agentic-actions.php   set tenant.model, tenant.parameter, tenant.membership and tenant.scope (https://agentic-actions.com/concepts#tenants)')
        ->expectsOutputToContain('tenant.scope keeps $context->find() and datasets to the tenant\'s rows: until it or Actions::scopeUsing() is set, they throw MissingContext')
        ->expectsOutputToContain('Continue each conversation with Actions::conversation($agent, $user, $tenant) (https://agentic-actions.com/copilot#one-conversation-per-tenant)')
        ->expectsConfirmation('Would you like to run all pending database migrations?', 'yes')
        ->assertSuccessful();

    $migrations = array_map(basename(...), File::glob(database_path('migrations/*_create_agent*_conversations_table.php')));
    sort($migrations);

    expect($migrations)->toHaveCount(2)
        ->and($migrations[0])->toEndWith('_create_agent_conversations_table.php')
        ->and($migrations[1])->toEndWith('_create_agentic_conversations_table.php')
        ->and(RecordingCommand::$calls)->toHaveKey('migrate');
});

it('offers to migrate while a migration it publishes has not run, and never for the app\'s own', function (string $table, bool $offers) {
    unranMigration($table);

    $command = $this->artisan('actions:install', ['--web' => true, '--no-interaction' => true]);

    if ($offers) {
        $command->expectsConfirmation('Would you like to run all pending database migrations?', 'yes');
    }

    $command->assertSuccessful()->run();

    expect(array_keys(RecordingCommand::$calls))->toBe($offers ? ['migrate'] : []);
})->with([
    'the app\'s own' => ['widgets', false],
    'laravel/ai\'s conversations' => ['agent_conversations', true],
    'the conversation store' => ['agentic_conversations', true],
    'the tables a copilot shows' => ['agentic_views', true],
    'Sanctum\'s tokens' => ['personal_access_tokens', true],
    'the OAuth connections' => ['agentic_mcp_connections', true],
]);

it('prints the tenant route line under the prefix most of the app\'s web routes use, or teams/{team}', function (array $routes, string $prefix) {
    config(['agentic-actions.tenant.parameter' => 'team']);

    foreach ($routes as $uri) {
        Route::middleware('web')->get($uri, fn () => 'page');
    }

    Route::post('mcp/t/{team}', fn () => 'mcp');

    $this->artisan('actions:install', ['--web' => true, '--tenancy' => true, '--no-interaction' => true])
        ->expectsOutputToContain("routes/web.php   Route::middleware('auth')->prefix('{$prefix}')->name('teams.')->group(fn () => Actions::routes(tenant: true));")
        ->assertSuccessful();

    // Tenancy alone publishes nothing: the conversations table comes with a copilot.
    expect(publishedMigrations()['agentic_conversations'])->toBe(0);
})->with([
    'no web route carries the parameter' => [[], 'teams/{team}'],
    'most web routes start with it' => [['{team}/dashboard', '{team}/settings', 'invitations/{team}'], '{team}'],
]);

it('leaves out the steps the app has taken: its routes, its tenant model, Sanctum\'s trait and the MCP tenant path', function () {
    config([
        'agentic-actions.tenant.model' => Team::class,
        'agentic-actions.mcp.tenant_path' => 'mcp/t/{tenant}',
        'auth.providers.users.model' => User::class,
    ]);

    Route::middleware('auth')->group(fn () => Actions::routes());

    $this->artisan('actions:install', ['--web' => true, '--tenancy' => true, '--mcp' => true, '--no-interaction' => true])
        ->expectsConfirmation('Would you like to run all pending database migrations?', 'no')
        ->doesntExpectOutputToContain('routes/web.php')
        ->doesntExpectOutputToContain('Each routes file needs')
        ->doesntExpectOutputToContain('set tenant.model')
        ->doesntExpectOutputToContain('HasApiTokens')
        ->doesntExpectOutputToContain('mcp.tenant_path')
        ->expectsOutputToContain('php artisan actions:check --update')
        ->assertSuccessful();
});

it('takes confirmations to mean a copilot, and says when the cache store is not shared', function () {
    $this->skipUnlessAi();

    config(['cache.default' => 'array']);

    $this->artisan('actions:install', ['--approvals' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Setting up: approvals, copilot.')
        ->expectsOutputToContain('.env   CACHE_STORE=database, or another store every server shares, for confirmations')
        ->expectsConfirmation('Would you like to run all pending database migrations?', 'no')
        ->assertSuccessful();

    expect(publishedMigrations()['agent_conversations'])->toBe(1);
});

it('suggests an action only while none is discovered, and the snapshot update while it is missing or stale or an action is still to make', function (?string $paths, string $snapshot, array $printed, array $left) {
    if ($paths !== null) {
        config(['agentic-actions.discovery.paths' => [$paths]]);
    }

    $scan = app(Scanner::class)->scan(config('agentic-actions.discovery.paths'));

    match ($snapshot) {
        'current' => Snapshot::write(Snapshot::build($scan)),
        'stale' => Snapshot::write(['version' => 1, 'actions' => [], 'agents' => []]),
        default => null,
    };

    $command = $this->artisan('actions:install', ['--web' => true, '--no-interaction' => true]);

    foreach ($printed as $step) {
        $command->expectsOutputToContain($step);
    }

    foreach ($left as $step) {
        $command->doesntExpectOutputToContain($step);
    }

    $command->assertSuccessful()->run();
})->with([
    'no action, no snapshot' => [sys_get_temp_dir().'/agentic-actions-no-actions', 'missing', ['php artisan make:agentic-action CreatePost', 'php artisan actions:check --update'], []],
    'no action, a current snapshot' => [sys_get_temp_dir().'/agentic-actions-no-actions', 'current', ['php artisan make:agentic-action CreatePost', 'php artisan actions:check --update'], []],
    'actions, no snapshot' => [null, 'missing', ['php artisan actions:check --update'], ['make:agentic-action']],
    'actions, a stale snapshot' => [null, 'stale', ['php artisan actions:check --update'], ['make:agentic-action']],
    'actions, a current snapshot' => [null, 'current', [], ['make:agentic-action', 'actions:check --update']],
]);

it('prints no Next heading once the app has taken every step', function () {
    Snapshot::write(Snapshot::build(app(Scanner::class)->scan(config('agentic-actions.discovery.paths'))));
    Route::middleware('auth')->group(fn () => Actions::routes());

    $this->artisan('actions:install', ['--web' => true, '--no-interaction' => true])
        ->doesntExpectOutputToContain('Next:')
        ->assertSuccessful();
});

it('says to require laravel/ai when it is missing, publishes none of its tables and suggests no agent', function () {
    $this->usePackages(['laravel/ai' => PackageStatus::Missing]);

    $this->artisan('actions:install', ['--copilot' => true, '--tenancy' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Agents need laravel/ai: run composer require laravel/ai, then php artisan actions:install again.')
        ->doesntExpectOutputToContain('make:agent Assistant')
        ->doesntExpectOutputToContain('Actions::conversation(')
        ->assertSuccessful();

    expect(publishedMigrations())->toBe(['agent_conversations' => 0, 'agentic_conversations' => 0, 'personal_access_tokens' => 0])
        ->and(RecordingCommand::$calls)->toBe([]);
});

it('leaves out make:agent once a discovered agent uses a toolset', function () {
    $this->skipUnlessAi();

    config(['agentic-actions.discovery.paths' => [...config('agentic-actions.discovery.paths'), dirname(__DIR__, 2).'/Fixtures/Discovery/Agents']]);

    $this->artisan('actions:install', ['--copilot' => true, '--no-interaction' => true])
        ->expectsOutputToContain("Schedule::command('model:prune'")
        ->doesntExpectOutputToContain('make:agent Assistant')
        ->expectsConfirmation('Would you like to run all pending database migrations?', 'no')
        ->assertSuccessful();
});

it('publishes Sanctum\'s table for MCP when Sanctum is installed', function () {
    $this->artisan('actions:install', ['--mcp' => true, '--no-interaction' => true])
        ->expectsOutputToContain("Sanctum's personal_access_tokens table")
        ->expectsOutputToContain('app/Models/User.php   use Laravel\Sanctum\HasApiTokens; (https://agentic-actions.com/mcp#recipe-tokens-and-clients)')
        ->expectsConfirmation('Would you like to run all pending database migrations?', 'no')
        ->assertSuccessful();

    expect(publishedMigrations()['personal_access_tokens'])->toBe(1)
        ->and(RecordingCommand::$calls)->not->toHaveKey('install:api');
});

it('runs install:api for MCP when Sanctum is missing, once the person agrees', function (string $answer, bool $installs) {
    $this->usePackages(['laravel/sanctum' => PackageStatus::Missing]);

    $command = $this->artisan('actions:install', ['--mcp' => true])
        ->expectsConfirmation('MCP clients sign in with Sanctum tokens, which this app does not have yet. Run php artisan install:api now?', $answer);

    if (! $installs) {
        $command->expectsOutputToContain('MCP needs Sanctum: run php artisan install:api.');
    }

    $command->assertSuccessful()->run();

    expect(isset(RecordingCommand::$calls['install:api']))->toBe($installs)
        ->and(RecordingCommand::$calls['install:api'][0]['without-migration-prompt'] ?? null)->toBe($installs ? true : null);
})->with([
    'yes' => ['yes', true],
    'no' => ['no', false],
]);

it('stops at a publish that fails or leaves nothing behind: FAILED, nothing that needs it, no migrations, a failing exit', function (int $exitCode) {
    $this->skipUnlessAi();

    app(Kernel::class)->registerCommand(new RecordingCommand('vendor:publish', '{--tag=} {--provider=}', $exitCode));
    // Sanctum's table is there and has not run, so a run without a failure would migrate.
    unranMigration('personal_access_tokens');

    $this->artisan('actions:install', ['--copilot' => true, '--tenancy' => true, '--mcp' => true, '--no-interaction' => true])
        ->expectsOutputToContain('FAILED')
        ->expectsOutputToContain('ALREADY THERE')
        ->doesntExpectOutputToContain('PUBLISHED')
        // The package's conversation table references laravel/ai's, so it waits for them.
        ->doesntExpectOutputToContain('The agentic_conversations table')
        ->expectsOutputToContain('A step above failed: fix it, then run php artisan actions:install again.')
        ->assertFailed();

    expect(array_column(RecordingCommand::$calls['vendor:publish'], 'tag'))->toBe(['agentic-actions-config'])
        ->and(array_column(RecordingCommand::$calls['vendor:publish'], 'provider'))->toBe(['Laravel\Ai\AiServiceProvider'])
        ->and(RecordingCommand::$calls)->not->toHaveKey('migrate');
})->with([
    'vendor:publish fails' => [1],
    'vendor:publish succeeds and publishes nothing' => [0],
]);

it('stops when install:api fails, and runs no migrations', function () {
    $this->usePackages(['laravel/sanctum' => PackageStatus::Missing]);
    app(Kernel::class)->registerCommand(new RecordingCommand('install:api', '{--without-migration-prompt}', 1));
    unranMigration('agent_conversations');

    $this->artisan('actions:install', ['--mcp' => true])
        ->expectsConfirmation('MCP clients sign in with Sanctum tokens, which this app does not have yet. Run php artisan install:api now?', 'yes')
        ->expectsOutputToContain('A step above failed: fix it, then run php artisan actions:install again.')
        ->assertFailed();

    expect(RecordingCommand::$calls)->toHaveKey('install:api')
        ->not->toHaveKey('migrate');
});

it('fails when the migrations it runs fail', function () {
    app(Kernel::class)->registerCommand(new RecordingCommand('migrate', '', 1));

    $this->artisan('actions:install', ['--mcp' => true, '--no-interaction' => true])
        ->expectsOutputToContain("Sanctum's personal_access_tokens table")
        ->expectsConfirmation('Would you like to run all pending database migrations?', 'yes')
        ->expectsOutputToContain('A step above failed: fix it, then run php artisan actions:install again.')
        ->assertFailed();

    expect(RecordingCommand::$calls)->toHaveKey('migrate');
});

it('publishes nothing twice and asks nothing when every migration has run', function () {
    $this->skipUnlessAi();

    $this->artisan('actions:install', ['--copilot' => true, '--tenancy' => true, '--mcp' => true])
        ->expectsConfirmation('Would you like to run all pending database migrations?', 'no')
        ->assertSuccessful();

    $first = publishedMigrations();
    RecordingCommand::$calls = [];

    // Mark them as run, as the migration the first run offered would have.
    foreach (File::glob(database_path('migrations/*_create_*_table.php')) as $file) {
        app('migrator')->getRepository()->log(basename($file, '.php'), 99);
    }

    $this->artisan('actions:install', ['--copilot' => true, '--tenancy' => true, '--mcp' => true])
        ->expectsOutputToContain('ALREADY THERE')
        ->doesntExpectOutputToContain('PUBLISHED')
        ->assertSuccessful();

    expect($first)->toBe(['agent_conversations' => 1, 'agentic_conversations' => 1, 'personal_access_tokens' => 1])
        ->and(publishedMigrations())->toBe($first)
        ->and(RecordingCommand::$calls)->toBe([]);
});

it('publishes the tables a copilot shows once, beside laravel/ai\'s, and prints their prune schedule', function () {
    $this->skipUnlessAi();

    $views = fn (): int => count(File::glob(database_path('migrations/*_create_agentic_views_table.php')));
    $prune = "routes/console.php   Schedule::command('model:prune', ['--model' => [\\AgenticActions\\Streaming\\AgenticView::class]])->daily();";

    $this->artisan('actions:install', ['--web' => true, '--no-interaction' => true])
        ->doesntExpectOutputToContain('The agentic_views table')
        ->doesntExpectOutputToContain($prune)
        ->assertSuccessful();

    expect($views())->toBe(0);

    $this->artisan('actions:install', ['--copilot' => true])
        ->expectsOutputToContain('The agentic_views table')
        ->expectsOutputToContain($prune)
        ->expectsConfirmation('Would you like to run all pending database migrations?', 'no')
        ->assertSuccessful();

    $this->artisan('actions:install', ['--copilot' => true])
        ->expectsConfirmation('Would you like to run all pending database migrations?', 'no')
        ->assertSuccessful();

    expect($views())->toBe(1);
});

describe('OAuth', function () {
    it('publishes the connections table once with OAuth on, asks to migrate, and prints only the steps still missing', function () {
        $this->useOAuth(Flow::teams());
        recordInstallCommands();

        $this->artisan('actions:install', ['--mcp' => true])
            ->expectsOutputToContain('The agentic_mcp_connections table')
            ->expectsOutputToContain('Before real people connect: https://agentic-actions.com/mcp#harden-the-oauth-setup')
            ->doesntExpectOutputToContain('passport:install')
            ->doesntExpectOutputToContain('Mcp::oauthRoutes()')
            ->doesntExpectOutputToContain('For Claude and ChatGPT connectors')
            ->expectsConfirmation('Would you like to run all pending database migrations?', 'yes')
            ->assertSuccessful();

        expect(publishedConnections())->toBe(1)
            ->and(RecordingCommand::$calls)->toHaveKey('migrate');

        foreach (File::glob(database_path('migrations/*_create_*_table.php')) as $file) {
            app('migrator')->getRepository()->log(basename($file, '.php'), 99);
        }

        RecordingCommand::$calls = [];

        $this->artisan('actions:install', ['--mcp' => true])
            ->expectsOutputToContain('ALREADY THERE')
            ->doesntExpectOutputToContain('PUBLISHED')
            ->assertSuccessful();

        expect(publishedConnections())->toBe(1)
            ->and(RecordingCommand::$calls)->toBe([]);
    });

    it('prints passport:install without keys, and the routes/ai.php line without Mcp::oauthRoutes()', function () {
        $this->useOAuth(Flow::teams(['passport.private_key' => null]), routes: null);
        recordInstallCommands();
        Passport::loadKeysFrom(sys_get_temp_dir().'/agentic-actions-no-keys-'.getmypid());

        $this->artisan('actions:install', ['--mcp' => true, '--no-interaction' => true])
            ->expectsOutputToContain('php artisan passport:install')
            ->expectsOutputToContain("routes/ai.php   Route::middleware('throttle:60,1')->group(fn () => Mcp::oauthRoutes());")
            ->expectsConfirmation('Would you like to run all pending database migrations?', 'no')
            ->assertSuccessful();
    });

    it('prints one line and publishes nothing of OAuth with Passport installed and no Passport guard', function () {
        $this->skipUnlessPassport();

        $this->artisan('actions:install', ['--mcp' => true, '--no-interaction' => true])
            ->expectsOutputToContain("For Claude and ChatGPT connectors (OAuth): a passport guard in config/auth.php, then config/agentic-actions.php   mcp.middleware => ['auth:sanctum,api', 'throttle:agentic-actions-mcp'], and run this again")
            ->doesntExpectOutputToContain('agentic_mcp_connections')
            ->doesntExpectOutputToContain('harden')
            ->expectsConfirmation('Would you like to run all pending database migrations?', 'no')
            ->assertSuccessful();

        expect(publishedConnections())->toBe(0);
    });

    it('prints the composer require line without Passport', function () {
        $this->usePackages(['laravel/passport' => PackageStatus::Missing]);

        $this->artisan('actions:install', ['--mcp' => true, '--no-interaction' => true])
            ->expectsOutputToContain('For Claude and ChatGPT connectors (OAuth): composer require laravel/passport, then https://agentic-actions.com/mcp#connect-claude-chatgpt-and-other-remote-clients-oauth')
            ->doesntExpectOutputToContain('a passport guard')
            ->expectsConfirmation('Would you like to run all pending database migrations?', 'no')
            ->assertSuccessful();

        expect(publishedConnections())->toBe(0);
    });

    it('keeps an app whose user model is on Passport\'s trait off Sanctum, and prints auth:api', function () {
        $this->skipUnlessPassport();
        config(['auth.providers.users.model' => PassportUser::class]);
        $this->usePackages(['laravel/sanctum' => PackageStatus::Missing]);

        $this->artisan('actions:install', ['--mcp' => true, '--no-interaction' => true])
            ->doesntExpectOutputToContain("Sanctum's personal_access_tokens table")
            ->doesntExpectOutputToContain('Laravel\Sanctum\HasApiTokens')
            ->expectsOutputToContain("mcp.middleware => ['auth:api', 'throttle:agentic-actions-mcp']")
            ->assertSuccessful();

        expect(publishedMigrations()['personal_access_tokens'])->toBe(0)
            ->and(RecordingCommand::$calls)->not->toHaveKey('install:api');
    });

    it('never publishes the connections table for a copilot with tenants, nor the conversations table for MCP', function () {
        $this->skipUnlessAi();
        $this->useOAuth(Flow::teams());
        recordInstallCommands();

        $this->artisan('actions:install', ['--copilot' => true, '--tenancy' => true, '--no-interaction' => true])
            ->expectsConfirmation('Would you like to run all pending database migrations?', 'no')
            ->assertSuccessful();

        expect(publishedConnections())->toBe(0)
            ->and(publishedMigrations()['agentic_conversations'])->toBe(1);

        forgetInstall();

        $this->artisan('actions:install', ['--mcp' => true, '--no-interaction' => true])
            ->expectsConfirmation('Would you like to run all pending database migrations?', 'no')
            ->assertSuccessful();

        expect(publishedConnections())->toBe(1)
            ->and(publishedMigrations()['agentic_conversations'])->toBe(0);
    });
});
