<?php

namespace AgenticActions\Console;

use AgenticActions\Discovery\Scanner;
use AgenticActions\Exceptions\DuplicateActionName;
use AgenticActions\Feed\ChangesController;
use AgenticActions\Http\ActionRequest;
use AgenticActions\Mcp\McpMount;
use AgenticActions\OAuth\Discovery;
use AgenticActions\Support\Migrations;
use AgenticActions\Support\Packages;
use AgenticActions\Support\PackageStatus;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Laravel\Sanctum\HasApiTokens;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

/**
 * Sets an app up for the features it uses: the config, the migrations those features need (laravel/ai's, the tables a
 * copilot shows, the package's conversation store, Sanctum's through install:api), the route lines and the next steps
 * not taken yet, then asks
 * before it migrates while one of those migrations has not run. Running it again publishes nothing that is already
 * there. A step that fails skips the steps that need it and the migrations, and the command exits with a failure; a
 * step the person declines is not one.
 *
 * @internal
 */
#[AsCommand(name: 'actions:install')]
final class InstallCommand extends Command
{
    /**
     * The features an app may use, as option => the prompt's label.
     */
    private const FEATURES = [
        'web' => 'Web routes: forms and JSON calls from your pages',
        'copilot' => 'Agents and a copilot, on laravel/ai',
        'approvals' => 'Confirmations before an agent deletes or sends something',
        'mcp' => 'MCP clients, with Sanctum tokens or OAuth (Passport)',
        'tenancy' => 'Tenants, such as teams',
    ];

    /**
     * The ends of the migration names this command publishes, the only migrations it offers to run.
     */
    private const MIGRATIONS = ['_create_agent_conversations_table', '_create_agentic_conversations_table', '_create_agentic_views_table', '_create_personal_access_tokens_table', '_create_agentic_mcp_connections_table'];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'actions:install
        {--web : The app calls actions from its pages and forms}
        {--copilot : The app runs agents or a copilot on laravel/ai}
        {--approvals : Agents ask the person before a Destructive or External action}
        {--mcp : MCP clients reach the actions with Sanctum tokens or OAuth (Passport)}
        {--tenancy : Actions belong to a tenant, such as a team}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set the app up for the Agentic Actions features it uses';

    /**
     * Execute the console command.
     */
    public function handle(Packages $packages): int
    {
        $features = $this->features($packages);
        $agents = array_intersect(['copilot', 'approvals'], $features) !== [];

        $this->components->info('Setting up: '.($features === [] ? 'the config only' : implode(', ', $features)).'.');
        $failed = ! $this->publish('config/agentic-actions.php', fn (): bool => is_file(config_path('agentic-actions.php')), ['--tag' => 'agentic-actions-config']);

        if ($agents && $packages->laravelAi() === PackageStatus::Missing) {
            $this->components->warn('Agents need laravel/ai: run composer require laravel/ai, then php artisan actions:install again.');
        } elseif ($agents) {
            // What laravel/ai's installation docs publish: its config, its conversation tables and its stubs. The
            // package's tables sit beside laravel/ai's, so they wait for them.
            if (! $this->publish("laravel/ai's conversation tables", fn (): bool => Migrations::published('create_agent_conversations_table'), ['--provider' => 'Laravel\Ai\AiServiceProvider'])) {
                $failed = true;
            } else {
                $failed = ! $this->publish('The agentic_views table', fn (): bool => Migrations::published('create_agentic_views_table'), ['--tag' => 'agentic-actions-views-migrations']) || $failed;

                if (in_array('tenancy', $features, true)) {
                    $failed = ! $this->publish('The agentic_conversations table', fn (): bool => Migrations::published('create_agentic_conversations_table'), ['--tag' => 'agentic-actions-migrations']) || $failed;
                }
            }
        }

        // An app whose user model is Passport's keeps off Sanctum: the two traits cannot share one model.
        if (in_array('mcp', $features, true) && ! $this->usesTrait('Laravel\Passport\HasApiTokens')) {
            $failed = ! $this->sanctum($packages) || $failed;
        }

        if (in_array('mcp', $features, true) && McpMount::oauth()) {
            $failed = ! $this->publish('The agentic_mcp_connections table', fn (): bool => Migrations::published('create_agentic_mcp_connections_table'), ['--tag' => 'agentic-actions-oauth-migrations']) || $failed;
        }

        $this->nextSteps($features, $packages);

        // After a failed step the migrations may be incomplete, so they wait for a run without one.
        if (! $failed && $this->pendingMigrations() && confirm('Would you like to run all pending database migrations?', default: true)) {
            $failed = $this->call('migrate') !== self::SUCCESS;
        }

        if ($failed) {
            $this->components->error('A step above failed: fix it, then run php artisan actions:install again.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The features the flags name; without a flag, the ones the person picks, or the web routes alone without
     * interaction. Confirmations run in a copilot, so they bring it.
     *
     * @return list<string>
     */
    private function features(Packages $packages): array
    {
        $features = array_values(array_filter(array_keys(self::FEATURES), fn (string $feature): bool => $this->option($feature) === true));

        if ($features === []) {
            $features = ! $this->input->isInteractive() ? ['web'] : array_values(array_map(strval(...), multiselect(
                label: 'Which features does this app use?',
                options: self::FEATURES,
                default: array_keys(array_filter([
                    'web' => true,
                    'copilot' => $packages->laravelAi() !== PackageStatus::Missing,
                    'tenancy' => config('agentic-actions.tenant.model') !== null,
                ])),
            )));
        }

        return in_array('approvals', $features, true) ? array_values(array_unique([...$features, 'copilot'])) : $features;
    }

    /**
     * Publish one thing through vendor:publish unless it is already there, and say which. False when the publish
     * failed: vendor:publish failed, or left the thing missing.
     *
     * @param  Closure(): bool  $present
     * @param  array<string, string>  $arguments
     */
    private function publish(string $what, Closure $present, array $arguments): bool
    {
        $there = $present();
        $published = ! $there && $this->callSilently('vendor:publish', $arguments) === self::SUCCESS && $present();

        $this->components->twoColumnDetail($what, match (true) {
            $there => '<fg=yellow;options=bold>ALREADY THERE</>',
            $published => '<fg=green;options=bold>PUBLISHED</>',
            default => '<fg=red;options=bold>FAILED</>',
        });

        return $there || $published;
    }

    /**
     * MCP clients sign in with Sanctum tokens: its migration when Sanctum is installed, else install:api, which asks
     * Composer for it. False when that failed; a person who declines install:api is told what to run instead.
     */
    private function sanctum(Packages $packages): bool
    {
        if ($packages->status('laravel/sanctum') !== PackageStatus::Missing) {
            return $this->publish("Sanctum's personal_access_tokens table", fn (): bool => Migrations::published('create_personal_access_tokens_table'), ['--tag' => 'sanctum-migrations']);
        }

        if (confirm('MCP clients sign in with Sanctum tokens, which this app does not have yet. Run php artisan install:api now?', default: true)) {
            return $this->call('install:api', ['--without-migration-prompt' => true]) === self::SUCCESS;
        }

        $this->components->warn('MCP needs Sanctum: run php artisan install:api.');

        return true;
    }

    /**
     * The route lines for routes/web.php, what to set, the commands that come next and, with a copilot, the schedule
     * that prunes the tables it showed, leaving out what the app already has: generated routes, a tenant model, Sanctum's trait on the user model, an MCP tenant path, each
     * OAuth step taken, a discovered action and a snapshot actions:check finds current (a new action makes it stale,
     * so the update follows it). The agent steps wait for laravel/ai. With nothing left, it prints nothing.
     *
     * @param  list<string>  $features
     */
    private function nextSteps(array $features, Packages $packages): void
    {
        $uses = fn (string $feature): bool => in_array($feature, $features, true);
        $routes = $this->laravel->make('router')->getRoutes()->getRoutes();
        $web = $uses('web') && array_filter($routes, fn (Route $route): bool => $route->getAction(ActionRequest::GENERATED) === true
            || $route->getControllerClass() === ChangesController::class) === [];
        $parameter = (string) config('agentic-actions.tenant.parameter', 'tenant');
        $plural = Str::plural($parameter);
        $passport = $this->usesTrait('Laravel\Passport\HasApiTokens');
        $oauth = $uses('mcp') && McpMount::oauth();
        $connectors = 'For Claude and ChatGPT connectors (OAuth): ';
        $ai = $uses('copilot') && $packages->laravelAi() !== PackageStatus::Missing;

        try {
            $scan = $this->laravel->make(Scanner::class)->scan(
                array_values(array_map(strval(...), (array) config('agentic-actions.discovery.paths', []))),
                array_values(array_map(strval(...), (array) config('agentic-actions.discovery.classes', []))),
            );
        } catch (DuplicateActionName) {
            $scan = null;
        }

        $steps = array_filter([
            $web && ! $uses('tenancy') ? "routes/web.php   Route::middleware('auth')->group(fn () => Actions::routes());" : null,
            $web && $uses('tenancy') ? "routes/web.php   Route::middleware('auth')->prefix('{$this->tenantPrefix($routes, $parameter)}')->name('{$plural}.')->group(fn () => Actions::routes(tenant: true));" : null,
            $web && $uses('tenancy') ? "routes/web.php   Route::middleware('auth')->group(fn () => Actions::routes(tenant: false));" : null,
            $web ? 'Each routes file needs: use AgenticActions\Facades\Actions;' : null,
            $uses('tenancy') && config('agentic-actions.tenant.model') === null ? 'config/agentic-actions.php   set tenant.model, tenant.parameter and tenant.membership (docs/concepts.md#tenants)' : null,
            $ai ? 'php artisan make:agent Assistant, then the chat route of docs/copilot.md' : null,
            $ai ? "routes/console.php   Schedule::command('model:prune', ['--model' => [\\AgenticActions\\Streaming\\AgenticView::class]])->daily();" : null,
            $ai && $uses('tenancy') ? 'Continue each conversation with Actions::conversation($agent, $user, $tenant) (docs/copilot.md#one-conversation-per-tenant)' : null,
            $uses('approvals') && ! in_array(config('cache.stores.'.config('cache.default').'.driver'), ['database', 'redis', 'memcached', 'dynamodb'], true)
                ? '.env   CACHE_STORE=database, or another store every server shares, for confirmations' : null,
            $uses('mcp') && ! $passport && ! $this->usesTrait(HasApiTokens::class) ? 'app/Models/User.php   use Laravel\Sanctum\HasApiTokens; (docs/mcp.md)' : null,
            $uses('mcp') && $uses('tenancy') && config('agentic-actions.mcp.tenant_path') === null ? "config/agentic-actions.php   mcp.tenant_path, such as mcp/t/{{$parameter}}" : null,
            $uses('mcp') && $packages->passport() === PackageStatus::Missing ? $connectors.'composer require laravel/passport, then docs/mcp.md#connect-claude-chatgpt-and-other-remote-clients-oauth' : null,
            $uses('mcp') && $packages->passport() !== PackageStatus::Missing && ! $oauth
                ? $connectors."a passport guard in config/auth.php, then config/agentic-actions.php   mcp.middleware => ['".($passport ? 'auth:api' : 'auth:sanctum,api')."', 'throttle:agentic-actions-mcp'], and run this again" : null,
            $oauth && ! (config('passport.private_key') || is_readable(Passport::keyPath('oauth-private.key'))) ? 'php artisan passport:install' : null,
            $oauth && ! $this->laravel->make('router')->has(Discovery::METADATA) ? "routes/ai.php   Route::middleware('throttle:60,1')->group(fn () => Mcp::oauthRoutes());" : null,
            $oauth ? 'Before real people connect: docs/mcp.md#harden-the-oauth-setup' : null,
            $scan?->actions === [] ? 'php artisan make:agentic-action CreatePost' : null,
            $scan === null || $scan->actions === [] || $this->laravel->make(Checks::class)->snapshot($scan) !== [] ? 'php artisan actions:check --update' : null,
        ]);

        if ($steps === []) {
            return;
        }

        $this->newLine();
        $this->line('  Next:');

        foreach ($steps as $step) {
            $this->line('  '.OutputFormatter::escape($step));
        }

        $this->newLine();
    }

    /**
     * Whether the app's user model uses a trait, named as a string so the command runs without its package.
     */
    private function usesTrait(string $trait): bool
    {
        $user = config('auth.providers.users.model');

        return is_string($user) && class_exists($user) && in_array($trait, class_uses_recursive($user), true);
    }

    /**
     * The prefix most of the app's web routes put before the tenant parameter, such as "{current_team}" or
     * "teams/{team}"; the parameter's plural before it while no web route carries it.
     *
     * @param  array<int, Route>  $routes
     */
    private function tenantPrefix(array $routes, string $parameter): string
    {
        $prefixes = [];

        foreach ($routes as $route) {
            if (in_array('web', (array) $route->middleware(), true) && ($at = strpos($route->uri(), "{{$parameter}}")) !== false) {
                $prefixes[] = substr($route->uri(), 0, $at + strlen($parameter) + 2);
            }
        }

        $counts = array_count_values($prefixes);
        arsort($counts);

        return (string) (array_key_first($counts) ?? Str::plural($parameter)."/{{$parameter}}");
    }

    /**
     * Whether a migration this command publishes is in database/migrations and has not run, or the migrations table
     * cannot be read. The app's own pending migrations never bring the question.
     */
    private function pendingMigrations(): bool
    {
        $migrator = $this->laravel->make('migrator');
        $ours = array_filter(array_keys($migrator->getMigrationFiles($this->laravel->databasePath('migrations'))), fn (string $name): bool => Str::endsWith($name, self::MIGRATIONS));

        return $ours !== [] && rescue(fn (): bool => ! $migrator->repositoryExists() || array_diff($ours, $migrator->getRepository()->getRan()) !== [], true, false);
    }
}
