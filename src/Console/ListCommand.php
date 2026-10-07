<?php

namespace AgenticActions\Console;

use AgenticActions\Contracts\ReadsTokenGrants;
use AgenticActions\Discovery\Scan;
use AgenticActions\Discovery\Scanner;
use AgenticActions\Discovery\Snapshot;
use AgenticActions\Exceptions\DuplicateActionName;
use AgenticActions\Exposure\Entry;
use AgenticActions\Http\ActionRequest;
use AgenticActions\Mcp\McpMount;
use AgenticActions\Pipeline\Authorizer;
use AgenticActions\Pipeline\AuthorizeTiming;
use AgenticActions\Security\TokenGrants;
use AgenticActions\Surface;
use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists every discovered action with the surfaces it reaches, from a fresh scan, never the registry: a scan records
 * exposure errors instead of throwing, so this command shows them in every context, a test run included.
 *
 * @internal
 */
#[AsCommand(name: 'actions:list')]
final class ListCommand extends Command
{
    /**
     * The width of a detail line's label column.
     */
    private const LABEL = 11;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'actions:list {--json : Print exactly the exposure snapshot}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List the discovered actions and the surfaces each one reaches';

    /**
     * Execute the console command.
     */
    public function handle(Scanner $scanner, Router $router): int
    {
        try {
            $scan = $scanner->scan(
                array_values(array_map(strval(...), (array) config('agentic-actions.discovery.paths', []))),
                array_values(array_map(strval(...), (array) config('agentic-actions.discovery.classes', []))),
            );
        } catch (DuplicateActionName $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->output->write(Snapshot::encode(Snapshot::build($scan)), false, OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        /** @var list<Route> $routes */
        $routes = array_values($router->getRoutes()->getRoutes());

        foreach ($scan->actions as $entry) {
            $this->block($entry, array_values(array_filter(
                $routes,
                fn (Route $route): bool => $route->getControllerClass() === $entry->class,
            )));
        }

        $this->warnings($scan);
        $this->nothingFound($scan);
        $this->routeHint($scan, $routes);
        $this->agents($scan);
        $this->tokenGuards();

        return self::SUCCESS;
    }

    /**
     * One action: its name and class, then one line per fact (the tables initialize() adds rows to only on a Read that
     * lists them), then its exposure errors in red.
     *
     * @param  list<Route>  $routes  every route whose controller is the action, generated or hand-written
     */
    private function block(Entry $entry, array $routes): void
    {
        $this->newLine();
        $this->components->twoColumnDetail("<fg=green;options=bold>{$entry->name}</>", $entry->class);

        $this->detail('effect', $entry->effect === null ? 'undeclared' : $entry->effect->value);
        $this->detail('web', ...($routes !== []
            ? array_map(self::describeRoute(...), $routes)
            : [$entry->allows(Surface::Http) ? 'open, no route yet' : self::closed($entry, 'http')]));
        $this->detail('agents', $entry->allows(Surface::Agent)
            ? 'toolsets: '.implode(', ', $entry->toolsets)
            : self::closed($entry, 'agent'));
        $this->detail('mcp', $entry->allows(Surface::Mcp) ? $this->mcp($entry) : self::closed($entry, 'mcp'));
        $this->detail('cli', "php artisan actions:run {$entry->name}");
        $this->detail('tenant', $entry->tenantScoped ? 'scoped' : 'not scoped');
        $this->detail('authorize', match (Authorizer::timing($entry->action())) {
            AuthorizeTiming::Early => 'before input',
            AuthorizeTiming::Late => 'after validation',
            AuthorizeTiming::Both => 'before input and after validation',
            AuthorizeTiming::Missing => 'missing: denied everywhere',
        });

        if ($entry->initializes !== []) {
            $this->detail('initialize', 'inserts into '.implode(', ', $entry->initializes).' only, before handle()');
        }

        foreach ($entry->errors as $error) {
            $this->line('  <fg=red>'.OutputFormatter::escape($error).'</>');
        }
    }

    /**
     * A labelled detail line; further lines continue under the value.
     */
    private function detail(string $label, string ...$values): void
    {
        foreach (array_values($values) as $index => $value) {
            $this->line('  '.str_pad($index === 0 ? $label : '', self::LABEL).OutputFormatter::escape($value));
        }
    }

    /**
     * Why a surface is not open: its skipped reason, the error that refused it, or plainly closed.
     */
    private static function closed(Entry $entry, string $surface): string
    {
        if (isset($entry->skipped[$surface])) {
            return "skipped: {$entry->skipped[$surface]}";
        }

        foreach ($entry->errors as $error) {
            if (str_starts_with($error, "{$surface}: ")) {
                return 'error: '.substr($error, strlen($surface) + 2);
            }
        }

        return 'closed';
    }

    /**
     * Where an MCP-open action is served: the package's route for its scope, or why that route is missing.
     */
    private function mcp(Entry $entry): string
    {
        $tenantScoped = config('agentic-actions.tenant.model') !== null && $entry->tenantScoped;
        $route = $this->laravel->make(Router::class)->getRoutes()->getByName($tenantScoped ? McpMount::TENANT_ROUTE : McpMount::ROUTE);

        if ($route?->getAction(McpMount::MARK) === true) {
            return 'open  POST '.$route->uri();
        }

        return 'open, not mounted: '.(McpMount::unmounted($tenantScoped) ?? 'the path belongs to another route');
    }

    /**
     * "METHOD /uri (name)".
     */
    private static function describeRoute(Route $route): string
    {
        $name = $route->getName();

        return implode('|', $route->methods()).' /'.ltrim($route->uri(), '/').($name === null ? '' : " ({$name})");
    }

    /**
     * The classes the scan could not load, in yellow.
     */
    private function warnings(Scan $scan): void
    {
        if ($scan->warnings === []) {
            return;
        }

        $this->newLine();

        foreach ($scan->warnings as $warning) {
            $this->components->warn(OutputFormatter::escape($warning));
        }
    }

    /**
     * Where the scanner looked when it found nothing, and how to add an action or point it elsewhere.
     */
    private function nothingFound(Scan $scan): void
    {
        if ($scan->actions !== []) {
            return;
        }

        $this->newLine();

        if ($scan->directories === []) {
            $this->line('  <fg=yellow>No actions found.</> Scanned: nothing (no configured path exists).');
        } else {
            $this->line('  <fg=yellow>No actions found.</> Scanned:');

            foreach ($scan->directories as $directory) {
                $this->line('    '.OutputFormatter::escape($directory));
            }
        }

        $this->line('  Create one with php artisan make:agentic-action CreatePost.');
        $this->line('  If yours live elsewhere, publish the config (php artisan vendor:publish --tag=agentic-actions-config)');
        $this->line('  and set discovery.paths in config/agentic-actions.php, for example Modules/*/app.');
    }

    /**
     * The two route lines, while some action opens the web and no generated route exists yet.
     *
     * @param  list<Route>  $routes
     */
    private function routeHint(Scan $scan, array $routes): void
    {
        $web = array_filter($scan->actions, fn (Entry $entry): bool => $entry->allows(Surface::Http));
        $generated = array_filter($routes, fn (Route $route): bool => $route->getAction(ActionRequest::GENERATED) === true);

        if ($web === [] || $generated !== []) {
            return;
        }

        $this->newLine();
        $this->line('  No generated routes yet. Add them inside your own middleware:');
        $this->newLine();
        $this->line("  routes/web.php   Route::middleware('auth')->group(fn () => Actions::routes());");
        $this->line("  routes/api.php   Route::middleware('auth:sanctum')->name('api.')->group(fn () => Actions::routes());");
        $this->newLine();
        $this->line('  Each file needs: use AgenticActions\\Facades\\Actions;');
    }

    /**
     * The #[UseToolset] agents, by toolset.
     */
    private function agents(Scan $scan): void
    {
        if ($scan->agents === []) {
            return;
        }

        $toolsets = [];

        foreach ($scan->agents as $agent => $names) {
            foreach ($names as $toolset) {
                $toolsets[$toolset][] = $agent;
            }
        }

        ksort($toolsets, SORT_STRING);

        $this->newLine();
        $this->line('  <options=bold>Agents per toolset</>');

        foreach ($toolsets as $toolset => $agents) {
            $this->line('  '.OutputFormatter::escape("{$toolset}: ".implode(', ', $agents)));
        }
    }

    /**
     * How the token reader treats each configured guard. It reads each guard's driver from config and never
     * resolves a guard, so a guard whose driver is not registered prints as unknown instead of crashing the command.
     * A Passport token grants only on the package's MCP URL its connection names, so the tokens of a Passport guard
     * that mcp.middleware does not name reach no action; its cookie reads as a session, as Sanctum's does.
     */
    private function tokenGuards(): void
    {
        $this->newLine();
        $this->line('  <options=bold>Token guards</>');

        $reader = $this->laravel->make(ReadsTokenGrants::class);

        if (! $reader instanceof TokenGrants) {
            $this->line('  custom reader: '.$reader::class);

            return;
        }

        $guards = array_map(strval(...), array_keys((array) config('auth.guards', [])));
        $width = max([0, ...array_map(strlen(...), $guards)]) + 2;

        $abilities = array_map(
            fn (string $effect): string => (string) config("agentic-actions.abilities.{$effect}"),
            ['read', 'write', 'destructive', 'external'],
        );

        foreach ($guards as $guard) {
            $lines = match ($reader->describe($guard)) {
                'session' => ['session: full access, as the session has'],
                'sanctum' => ['Sanctum: personal access token abilities ('.implode(', ', $abilities).')'],
                'passport' => [in_array($guard, McpMount::guards(), true)
                    ? "Passport: OAuth scopes ({$abilities[0]}, {$abilities[1]}), only on the MCP URL the person approved"
                    : 'Passport: its tokens reach no action, since agentic-actions.mcp.middleware does not name this guard'],
                default => [
                    'unknown to the token reader: every action reads as not found for it.',
                    'Bind AgenticActions\Contracts\ReadsTokenGrants to read its grants.',
                ],
            };

            foreach ($lines as $index => $line) {
                $this->line('  '.str_pad($index === 0 ? $guard : '', $width).OutputFormatter::escape($line));
            }
        }
    }
}
