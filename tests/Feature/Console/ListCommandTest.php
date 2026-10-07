<?php

use AgenticActions\Contracts\ReadsTokenGrants;
use AgenticActions\Discovery\Scanner;
use AgenticActions\Discovery\Snapshot;
use AgenticActions\Mcp\McpMount;
use AgenticActions\Support\PackageStatus;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\Contracts\Tool;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\PlainNote;
use Tests\Fixtures\Authorize\TwoStepAuthorize;
use Tests\Fixtures\Checks\HeaderTokenReader;
use Tests\Fixtures\Checks\McpAccount;
use Tests\Fixtures\Checks\McpRoutes;
use Tests\Fixtures\Discovery\Segments\KebabNamed;
use Tests\Fixtures\Initialize\ShowShareLink;
use Tests\Fixtures\Misconfigured\DestructiveForAgents;
use Tests\Fixtures\Misconfigured\ExposesNothing;
use Tests\Fixtures\Misconfigured\NoAuthorize;
use Tests\Fixtures\Misconfigured\UndeclaredEffect;

beforeEach(function () {
    $this->fixtures = dirname(__DIR__, 2).'/Fixtures';
});

/**
 * Run actions:list and return its exit code and everything it printed.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{0: int, 1: string}
 */
function listActionsCommand(array $parameters = []): array
{
    $code = Artisan::call('actions:list', $parameters);

    return [$code, Artisan::output()];
}

/**
 * The lines of one action's block, from its name line to the line before the next blank line.
 *
 * @return list<string>
 */
function actionBlock(string $output, string $name): array
{
    $lines = explode("\n", $output);
    $block = [];

    foreach ($lines as $index => $line) {
        if (preg_match('/^  '.preg_quote($name, '/').' \.*/', $line) === 1) {
            for ($next = $index + 1; isset($lines[$next]) && trim($lines[$next]) !== ''; $next++) {
                $block[] = $lines[$next];
            }

            break;
        }
    }

    return $block;
}

describe('blocks', function () {
    it('prints one block per action, sorted by name, with its routes, surfaces, CLI line, tenancy and authorize timing', function () {
        $this->usePackages(['laravel/ai' => PackageStatus::Missing]);

        // save-note's class sorts after every class under Fixtures/Actions, but its name sorts among theirs.
        config(['agentic-actions.discovery.classes' => [KebabNamed::class]]);

        $this->mountRoutes(function () {
            Route::middleware('auth')->prefix('actions')->name('actions.')->group(fn () => $this->generatedRoute(CreateNote::class));
            Route::middleware('auth:sanctum')->prefix('api/actions')->name('api.actions.')->group(fn () => $this->generatedRoute(CreateNote::class));
            McpRoutes::mount('mcp/actions');
        });

        [$code, $output] = listActionsCommand();

        preg_match_all('/^  ([a-z-]+) \.+ /m', $output, $names);

        $sorted = array_keys(app(Scanner::class)->scan(config('agentic-actions.discovery.paths'), [KebabNamed::class])->actions);

        sort($sorted, SORT_STRING);

        expect($code)->toBe(0)
            ->and($names[1])->toContain('save-note')
            ->and($names[1])->toBe($sorted)
            ->and($output)->toMatch('/^  create-note \.+ Tests\\\\Fixtures\\\\Actions\\\\CreateNote *$/m')
            ->and(actionBlock($output, 'create-note'))->toBe([
                '  effect     write',
                '  web        POST /actions/create-note (actions.create-note)',
                '             POST /api/actions/create-note (api.actions.create-note)',
                '  agents     skipped: laravel/ai is not installed',
                '  mcp        open  POST mcp/actions',
                '  cli        php artisan actions:run create-note',
                '  tenant     not scoped',
                '  authorize  before input',
            ]);
    });

    it('shows a web surface with no route yet, a surface not declared, and an action exposed nowhere', function () {
        $this->usePackages(['laravel/ai' => PackageStatus::Missing]);

        [, $output] = listActionsCommand();

        expect(actionBlock($output, 'publish-note'))->toContain('  web        open, no route yet', '  agents     skipped: not declared', '  mcp        skipped: not declared')
            ->and(actionBlock($output, 'plain-note'))->toContain('  web        skipped: no #[Expose]', '  agents     skipped: no #[Expose]', '  mcp        skipped: no #[Expose]')
            ->and(actionBlock($output, 'delete-note'))->toContain('  effect     destructive', '  agents     skipped: effect destructive: offered to agents only when #[Expose(agents: [...])] names a toolset', '  mcp        skipped: effect destructive: MCP has no confirmation step');
    });

    it('lists a hand-written route to an action exposed nowhere', function () {
        $this->mountRoutes(fn () => Route::post('notes/plain', PlainNote::class)->name('notes.plain'));

        [, $output] = listActionsCommand();

        expect(actionBlock($output, 'plain-note'))->toContain('  web        POST /notes/plain (notes.plain)');
    });

    it('shows the toolsets once laravel/ai opens the agent surface', function () {
        $this->skipUnlessAi();

        [, $output] = listActionsCommand();

        expect(actionBlock($output, 'create-note'))->toContain('  agents     toolsets: default')
            ->and(actionBlock($output, 'list-notes'))->toContain('  agents     toolsets: default');
    });

    it('shows when authorize() runs, or that it is missing', function () {
        config(['agentic-actions.discovery.classes' => [NoAuthorize::class, TwoStepAuthorize::class]]);

        [, $output] = listActionsCommand();

        expect(actionBlock($output, 'late-authorize'))->toContain('  authorize  after validation')
            ->and(actionBlock($output, 'create-note'))->toContain('  authorize  before input')
            ->and(actionBlock($output, 'two-step-authorize'))->toContain('  authorize  before input and after validation')
            ->and(actionBlock($output, 'no-authorize'))->toContain('  authorize  missing: denied everywhere');
    });

    it('shows a tenant-scoped action once a tenant model is set', function () {
        $this->useTeamTenancy();

        [, $output] = listActionsCommand();

        expect(actionBlock($output, 'team-note'))->toContain('  tenant     scoped')
            ->and(actionBlock($output, 'create-note'))->toContain('  tenant     scoped');
    });

    it('shows the tables a Read\'s initialize() adds rows to, and no such line for any other action', function () {
        config(['agentic-actions.discovery.classes' => [ShowShareLink::class]]);

        [, $output] = listActionsCommand();

        expect(actionBlock($output, 'show-share-link'))->toContain('  authorize  before input and after validation', '  initialize inserts into share_links only, before handle()')
            ->and(implode("\n", actionBlock($output, 'create-note')))->not->toContain('initialize');
    });

    it('prints exposure errors under their block inside a test run, where the registry would throw', function () {
        config([
            'agentic-actions.discovery.paths' => [],
            'agentic-actions.discovery.classes' => [UndeclaredEffect::class, DestructiveForAgents::class, ExposesNothing::class],
        ]);

        [$code, $output] = listActionsCommand();

        expect($code)->toBe(0)
            ->and(actionBlock($output, 'undeclared-effect'))->toContain('  effect     undeclared', '  web        error: effect undeclared', '  http: effect undeclared', '  agent: effect undeclared')
            ->and(actionBlock($output, 'exposes-nothing'))->toContain('  web        closed', '  #[Expose] names no surface');

        // A Destructive action that names a toolset is no mistake; without laravel/ai, the named agent surface is.
        expect(actionBlock($output, 'destructive-for-agents'))->toContain('  effect     destructive', '  mcp        skipped: not declared')
            ->and(implode("\n", actionBlock($output, 'destructive-for-agents')))->toContain(interface_exists(Tool::class) ? '  agents     toolsets: x' : '  agents     error: laravel/ai is not installed');
    });
});

describe('hints', function () {
    it('prints the two route lines while no generated route exists, and not after one is registered', function () {
        [, $before] = listActionsCommand();

        $this->mountRoutes(fn () => Route::middleware('auth')->group(fn () => $this->generatedRoute(CreateNote::class)));

        [, $after] = listActionsCommand();

        $lines = [
            'No generated routes yet. Add them inside your own middleware:',
            "  routes/web.php   Route::middleware('auth')->group(fn () => Actions::routes());",
            "  routes/api.php   Route::middleware('auth:sanctum')->name('api.')->group(fn () => Actions::routes());",
            '  Each file needs: use AgenticActions\\Facades\\Actions;',
        ];

        expect($before)->toContain(...$lines)
            ->and($after)->not->toContain($lines[0]);
    });

    it('does not print the route lines when no action opens the web', function () {
        config(['agentic-actions.discovery.classes' => [PlainNote::class], 'agentic-actions.discovery.paths' => []]);

        [, $output] = listActionsCommand();

        expect($output)->not->toContain('No generated routes yet.');
    });

    it('prints the scanned directories and the path hint when nothing is found', function () {
        config(['agentic-actions.discovery.paths' => [$this->fixtures.'/Discovery/Plain', $this->fixtures.'/Discovery/Agents']]);

        [$code, $output] = listActionsCommand();

        expect($code)->toBe(0)
            ->and($output)->toContain(
                'No actions found. Scanned:',
                '    '.$this->fixtures.'/Discovery/Plain',
                '    '.$this->fixtures.'/Discovery/Agents',
                'Create one with php artisan make:agentic-action CreatePost.',
                'If yours live elsewhere, publish the config (php artisan vendor:publish --tag=agentic-actions-config)',
                'and set discovery.paths in config/agentic-actions.php, for example Modules/*/app.',
            );
    });

    it('says nothing was scanned when no configured path exists', function () {
        config(['agentic-actions.discovery.paths' => ['missing-dir', $this->fixtures.'/Nowhere/*']]);

        [$code, $output] = listActionsCommand();

        expect($code)->toBe(0)
            ->and($output)->toContain(
                'No actions found. Scanned: nothing (no configured path exists).',
                'and set discovery.paths in config/agentic-actions.php, for example Modules/*/app.',
            );
    });

    it('prints a warning for a class that could not be loaded, and lists the rest', function () {
        config(['agentic-actions.discovery.paths' => [$this->fixtures.'/Actions', $this->fixtures.'/Discovery/Broken']]);

        [$code, $output] = listActionsCommand();

        expect($code)->toBe(0)
            ->and($output)->toContain('Tests\Fixtures\Discovery\Broken\Unloadable could not be loaded:')
            ->and(actionBlock($output, 'create-note'))->not->toBe([]);
    });
});

describe('agents and guards', function () {
    it('lists the agents per toolset', function () {
        config(['agentic-actions.discovery.paths' => [$this->fixtures.'/Actions', $this->fixtures.'/Discovery/Agents']]);

        [, $output] = listActionsCommand();

        expect($output)->toContain(
            'Agents per toolset',
            '  default: Tests\Fixtures\Discovery\Agents\BlogWriter',
            '  support: Tests\Fixtures\Discovery\Agents\SupportDesk',
        );
    });

    it('marks an agent that finds a toolset through tool search', function () {
        config(['agentic-actions.discovery.paths' => [$this->fixtures.'/Actions', $this->fixtures.'/Discovery/Deferring']]);

        [, $output] = listActionsCommand();

        expect($output)->toContain(
            '  default: Tests\Fixtures\Discovery\Deferring\DeferringDesk'."\n",
            '  support: Tests\Fixtures\Discovery\Deferring\DeferringDesk (deferred), Tests\Fixtures\Discovery\Deferring\SearchDesk (deferred)',
        );
    });

    it('describes each configured guard from its driver, without resolving it', function () {
        config([
            'auth.guards.api-key' => ['driver' => 'api-key'],
            'auth.guards.passport' => ['driver' => 'passport', 'provider' => 'users'],
            'auth.guards.partner' => ['driver' => 'passport', 'provider' => 'users'],
            'agentic-actions.mcp.middleware' => ['auth:sanctum,passport', 'throttle:agentic-actions-mcp'],
        ]);

        [$code, $output] = listActionsCommand();

        expect($code)->toBe(0)
            ->and($output)->toContain(
                "Token guards\n",
                "  web       session: full access, as the session has\n",
                "  sanctum   Sanctum: personal access token abilities (actions:read, actions:write, actions:destructive, actions:external)\n",
                "  api-key   unknown to the token reader: every action reads as not found for it.\n            Bind AgenticActions\\Contracts\\ReadsTokenGrants to read its grants.\n",
                "  passport  Passport: OAuth scopes (actions:read, actions:write), only on the MCP URL the person approved\n",
                "  partner   Passport: its tokens reach no action, since agentic-actions.mcp.middleware does not name this guard\n",
            );
    });

    it('names a custom token reader instead of describing the guards', function () {
        $this->app->bind(ReadsTokenGrants::class, HeaderTokenReader::class);

        [, $output] = listActionsCommand();

        expect($output)->toContain("Token guards\n  custom reader: Tests\\Fixtures\\Checks\\HeaderTokenReader\n")
            ->and($output)->not->toContain('session: full access');
    });
});

describe('mcp line', function () {
    // Each test starts from an empty route collection and registers the package's MCP routes by hand (McpRoutes),
    // whether or not the mount ran at boot.
    beforeEach(function () {
        app('router')->setRoutes(new RouteCollection);

        config(['agentic-actions.discovery.classes' => [McpAccount::class]]);

        $this->refreshActions();
    });

    it('names the tenant path for a tenant-scoped action, and the base path for the rest', function () {
        $this->useTeamTenancy();
        config(['agentic-actions.mcp.tenant_path' => 'mcp/t/{team}']);

        McpRoutes::mount('mcp/actions');
        McpRoutes::mount('mcp/t/{team}', McpMount::TENANT_ROUTE);

        [, $output] = listActionsCommand();

        expect(actionBlock($output, 'team-note'))->toContain('  mcp        open  POST mcp/t/{team}')
            ->and(actionBlock($output, 'mcp-account'))->toContain('  mcp        open  POST mcp/actions');
    });

    it('says why nothing is mounted, as McpMount::unmounted() gives it', function (array $settings, bool $tenancy, string $name, string $line) {
        if ($tenancy) {
            $this->useTeamTenancy();
        }

        config($settings);

        expect(actionBlock(listActionsCommand()[1], $name))->toContain('  mcp        '.$line);
    })->with([
        'MCP off' => [['agentic-actions.surfaces.mcp' => false], false, 'create-note', 'open, not mounted: surfaces.mcp is off'],
        'no guard' => [['agentic-actions.mcp.middleware' => ['throttle:agentic-actions-mcp']], false, 'create-note', 'open, not mounted: mcp.middleware names no configured guard'],
        'a null path' => [['agentic-actions.mcp.path' => null], false, 'create-note', 'open, not mounted: agentic-actions.mcp.path is null'],
        'a null tenant path' => [[], true, 'team-note', 'open, not mounted: agentic-actions.mcp.tenant_path is null'],
        'a tenant path without the parameter' => [['agentic-actions.mcp.tenant_path' => 'mcp/t/{tenant}'], true, 'team-note', 'open, not mounted: agentic-actions.mcp.tenant_path has no {team}'],
    ]);

    it('says the path belongs to another route when it should mount and the package\'s route is missing', function () {
        Route::post('mcp/actions', fn () => 'mine');

        expect(actionBlock(listActionsCommand()[1], 'create-note'))->toContain('  mcp        open, not mounted: the path belongs to another route');
    });
});

describe('--json and errors', function () {
    it('prints exactly the exposure snapshot with --json', function () {
        $scan = app(Scanner::class)->scan(config('agentic-actions.discovery.paths'), config('agentic-actions.discovery.classes'));

        [$code, $output] = listActionsCommand(['--json' => true]);

        expect($code)->toBe(0)->and($output)->toBe(Snapshot::encode(Snapshot::build($scan)));
    });

    it('prints a duplicate name as an error and exits 1', function () {
        config(['agentic-actions.discovery.paths' => [$this->fixtures.'/Misconfigured']]);

        [$code, $output] = listActionsCommand();

        expect($code)->toBe(1)->and($output)->toContain('share the name or route segment [same].');
    });
});
