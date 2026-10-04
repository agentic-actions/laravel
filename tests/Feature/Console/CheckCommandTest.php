<?php

use AgenticActions\Console\Checks;
use AgenticActions\Console\Finding;
use AgenticActions\Discovery\Manifest;
use AgenticActions\Discovery\Scanner;
use AgenticActions\Discovery\Snapshot;
use AgenticActions\Support\PackageStatus;
use AgenticActions\Views\Column;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\LateAuthorize;
use Tests\Fixtures\Actions\ListNotes;
use Tests\Fixtures\Actions\PlainNote;
use Tests\Fixtures\Actions\TeamNote;
use Tests\Fixtures\Ai\MixedAgent;
use Tests\Fixtures\Ai\NotesAgent;
use Tests\Fixtures\Ai\OwnToolsAgent;
use Tests\Fixtures\Checks\EmptyRequiredOutput;
use Tests\Fixtures\Checks\FileForAgents;
use Tests\Fixtures\Checks\ForbiddenSecret;
use Tests\Fixtures\Checks\HttpOnlyAttributes;
use Tests\Fixtures\Checks\IdEarlyAuthorize;
use Tests\Fixtures\Checks\IdScopedExists;
use Tests\Fixtures\Checks\IdUnscopedExists;
use Tests\Fixtures\Checks\OrderWithoutShouldRegister;
use Tests\Fixtures\Checks\RulesNeedActor;
use Tests\Fixtures\Checks\RulesOnlyAgentKey;
use Tests\Fixtures\Checks\TenantKeyInSchema;
use Tests\Fixtures\Checks\UnsupportedUnion;
use Tests\Fixtures\Misconfigured\NoAuthorize;
use Tests\Fixtures\Misconfigured\UndeclaredEffect;
use Tests\Fixtures\Misconfigured\WebWithAgentSchema;
use Tests\Fixtures\Views\Invalid\BadColumns;
use Tests\Fixtures\Views\Invalid\WriteWithTable;
use Tests\Fixtures\Views\PostStats;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

const STRICT_RECIPE = 'the "Strict agent schemas (no ids)" recipe (https://agentic-actions.com/recipes#strict-agent-schemas-no-ids): agentSchema() plus fromAgent().';

beforeEach(function () {
    $this->fixtures = dirname(__DIR__, 2).'/Fixtures';
    $this->snapshot = Snapshot::path();
    $this->manifest = Manifest::path($this->app);

    // The user model on Sanctum's trait, as the MCP guard row expects after php artisan install:api.
    config(['auth.providers.users.model' => User::class]);

    File::delete([$this->snapshot, $this->manifest]);
});

afterEach(function () {
    File::delete([$this->snapshot, $this->manifest]);
});

/**
 * Run actions:check and return its exit code and everything it printed.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{0: int, 1: string}
 */
function checkActionsCommand(array $parameters = []): array
{
    $code = Artisan::call('actions:check', $parameters);

    return [$code, Artisan::output()];
}

/**
 * The findings for exactly these classes (and paths), as [level, row, message] triples, the Snapshot row left out.
 *
 * @param  list<class-string>  $classes
 * @param  list<string>  $paths
 * @return list<array{0: string, 1: string, 2: string}>
 */
function findingsFor(array $classes, array $paths = [], bool $production = false): array
{
    config(['agentic-actions.discovery.paths' => $paths, 'agentic-actions.discovery.classes' => $classes]);

    return array_values(array_map(
        fn (Finding $finding): array => [$finding->level, $finding->row, $finding->message],
        array_filter(app(Checks::class)->run($production), fn (Finding $finding): bool => $finding->row !== 'Snapshot'),
    ));
}

/**
 * The findings of one row.
 *
 * @param  list<array{0: string, 1: string, 2: string}>  $findings
 * @return list<array{0: string, 1: string, 2: string}>
 */
function inRow(array $findings, string $row): array
{
    return array_values(array_filter($findings, fn (array $finding): bool => $finding[1] === $row));
}

describe('the snapshot', function () {
    it('fails without the snapshot, and --update writes it so the next run passes on the shared fixtures', function (PackageStatus $ai) {
        if ($ai === PackageStatus::Installed) {
            $this->skipUnlessAi();
        }

        $this->usePackages(['laravel/ai' => $ai]);

        [$missingCode, $missing] = checkActionsCommand();
        [$updateCode, $update] = checkActionsCommand(['--update' => true]);
        [$code, $output] = checkActionsCommand();

        expect($missingCode)->toBe(1)
            ->and($missing)->toContain("✗ [Snapshot] {$this->snapshot} is missing: run php artisan actions:check --update")
            ->and($updateCode)->toBe(0)
            ->and($update)->toContain("Wrote {$this->snapshot}.")
            ->and(File::get($this->snapshot))->toBe(Snapshot::encode(Snapshot::build(app(Scanner::class)->scan(config('agentic-actions.discovery.paths')))))
            ->and($code)->toBe(0)
            ->and($output)->not->toContain('✗');
    })->with(['laravel/ai installed' => PackageStatus::Installed, 'laravel/ai missing' => PackageStatus::Missing]);

    it('names the default snapshot file in its message', function () {
        config(['agentic-actions.snapshot' => 'actions.exposure.json']);

        expect(inRow(array_map(
            fn (Finding $finding): array => [$finding->level, $finding->row, $finding->message],
            app(Checks::class)->run(),
        ), 'Snapshot'))->toBe([['fail', 'Snapshot', 'actions.exposure.json is missing: run php artisan actions:check --update']]);
    });

    it('fails a stale snapshot and names the added, removed and changed actions', function () {
        $snapshot = Snapshot::build(app(Scanner::class)->scan(config('agentic-actions.discovery.paths')));

        unset($snapshot['actions']['plain-note']);
        $snapshot['actions']['ghost-note'] = ['class' => 'App\Actions\GhostNote', 'effect' => 'write', 'web' => true, 'agents' => [], 'mcp' => false, 'tenant_scoped' => false];
        $snapshot['actions']['create-note']['web'] = false;
        $snapshot['actions']['list-notes']['effect'] = 'write';

        Snapshot::write($snapshot);

        [$code, $output] = checkActionsCommand();

        expect($code)->toBe(1)
            ->and($output)->toContain("✗ [Snapshot] {$this->snapshot} is stale: review the change, then run php artisan actions:check --update. Added: plain-note. Removed: ghost-note. Changed: create-note, list-notes.");
    });

    it('fails a snapshot recorded before laravel/ai arrived, naming the widened action', function () {
        $this->skipUnlessAi();
        $this->usePackages(['laravel/ai' => PackageStatus::Missing]);

        checkActionsCommand(['--update' => true]);

        $this->usePackages(['laravel/ai' => PackageStatus::Installed]);

        [$code, $output] = checkActionsCommand();

        expect($code)->toBe(1)->and($output)->toContain('is stale: review the change, then run php artisan actions:check --update. Changed: create-note,');
    });

    it('fails an unreadable snapshot as stale', function () {
        File::put($this->snapshot, '{"actions": ');

        [$code, $output] = checkActionsCommand();

        expect($code)->toBe(1)->and($output)->toContain("✗ [Snapshot] {$this->snapshot} is stale: review the change, then run php artisan actions:check --update");
    });
});

describe('the shared fixtures', function () {
    it('pass every row but Snapshot, with and without laravel/ai', function (PackageStatus $ai) {
        if ($ai === PackageStatus::Installed) {
            $this->skipUnlessAi();
        }

        $this->usePackages(['laravel/ai' => $ai]);

        $findings = findingsFor([], [$this->fixtures.'/Actions']);

        expect(array_filter($findings, fn (array $finding): bool => $finding[0] === 'fail'))->toBe([])
            ->and($findings)->toContain(['warn', 'Oracles', LateAuthorize::class.': rules() checks [post_id] with an exists rule, so any caller who reaches the action learns whether a matching record exists.']);
    })->with(['laravel/ai installed' => PackageStatus::Installed, 'laravel/ai missing' => PackageStatus::Missing]);
});

describe('discovery rows', function () {
    it('fails each exposure error, and an undeclared effect', function () {
        $findings = findingsFor([UndeclaredEffect::class]);

        expect(inRow($findings, 'Exposure'))->toContain(['fail', 'Exposure', UndeclaredEffect::class.': http: effect undeclared'])
            ->and(inRow($findings, 'Effect'))->toBe([['fail', 'Effect', UndeclaredEffect::class.': $effect is undeclared, so nothing remote reaches it. Declare Effect::Read, Effect::Write, Effect::Destructive or Effect::External.']]);
    });

    it('fails web named on an agentSchema() action, saying why and pointing to requiredForAgents()', function () {
        $findings = findingsFor([WebWithAgentSchema::class]);

        expect(inRow($findings, 'Exposure'))->toBe([['fail', 'Exposure', WebWithAgentSchema::class.': http: agentSchema(): schema() then holds what fromAgent() builds, such as ids an agent never saw, not what a form sends; write this route by hand, or, when agents only need more fields than the web, drop agentSchema() and name them in requiredForAgents()']]);
    });

    it('warns about a class under the paths that could not be loaded', function () {
        $findings = findingsFor([], [$this->fixtures.'/Discovery/Broken']);

        expect(inRow($findings, 'Discovery'))->toHaveCount(1)
            ->and($findings[0][0])->toBe('warn')
            ->and($findings[0][2])->toStartWith('Tests\Fixtures\Discovery\Broken\Unloadable could not be loaded: ');
    });

    it('fails two actions that share a name, and still runs the rows that need no scan', function () {
        config(['agentic-actions.tenant.model' => Team::class]);

        $findings = findingsFor([], [$this->fixtures.'/Misconfigured']);

        expect($findings)->toHaveCount(2)
            ->and($findings[0][0])->toBe('fail')
            ->and($findings[0][1])->toBe('Names')
            ->and($findings[0][2])->toEndWith('share the name or route segment [same].')
            ->and($findings[1][1])->toBe('Tenancy');
    });

    it('fails an action with no authorize()', function () {
        expect(inRow(findingsFor([NoAuthorize::class]), 'Authorization'))->toBe([
            ['fail', 'Authorization', NoAuthorize::class.': authorize() is missing, so every caller is denied. Add authorize(ActionContext $context), or authorize(ActionContext $context, ValidatedInput $input) to check the input.'],
        ]);
    });
});

describe('tenancy rows', function () {
    it('fails a tenant model without a membership check', function () {
        config(['agentic-actions.tenant.model' => Team::class]);

        expect(inRow(findingsFor([CreateNote::class]), 'Tenancy'))->toBe([
            ['fail', 'Tenancy', 'tenant.model is set, but no membership check is: set tenant.membership in config/agentic-actions.php, or call Actions::membershipUsing().'],
        ]);
    });

    it('fails a schema() key named like the tenant', function () {
        $this->useTeamTenancy();

        expect(inRow(findingsFor([TenantKeyInSchema::class]), 'Tenancy'))->toBe([
            ['fail', 'Tenancy', TenantKeyInSchema::class.": schema() declares [team_id], which is the tenant model's foreign key, so a caller could choose the tenant. The tenant comes from the route: read it with \$context->tenant()."],
        ]);
    });

    it('fails a generated route of a tenant-scoped action with no tenant parameter in its URI', function () {
        $this->useTeamTenancy();

        $this->mountRoutes(fn () => Route::middleware('auth')->group(function () {
            Route::prefix('actions')->name('actions.')->group(fn () => $this->generatedRoute(TeamNote::class));
            Route::prefix('teams/{team}/actions')->name('teams.actions.')->group(fn () => $this->generatedRoute(TeamNote::class));
        }));

        expect(inRow(findingsFor([TeamNote::class]), 'Tenancy'))->toBe([
            ['fail', 'Tenancy', TeamNote::class.': the generated route [actions.team-note] has no {team} parameter, so the action cannot find its tenant. Mount it with Actions::routes(tenant: true) inside a group whose prefix carries {team}.'],
        ]);
    });
});

describe('toolset rows', function () {
    it('fails a #[UseToolset] toolset no action joins', function () {
        $findings = inRow(findingsFor([], [$this->fixtures.'/Actions', $this->fixtures.'/Discovery/Agents']), 'Toolsets');

        expect($findings)->toContain(['fail', 'Toolsets', 'Tests\Fixtures\Discovery\Agents\SupportDesk: #[UseToolset] names [support], which no action joins.']);
    });

    it('passes a toolset some action joins', function () {
        $this->skipUnlessAi();

        $findings = findingsFor([], [$this->fixtures.'/Actions', $this->fixtures.'/Discovery/Agents', $this->fixtures.'/Discovery/Toolsets']);

        expect(inRow($findings, 'Toolsets'))->toBe([]);
    });

    it('warns about a toolset named in #[Expose(agents: …)] that no agent uses', function () {
        $this->skipUnlessAi();

        $findings = inRow(findingsFor([], [$this->fixtures.'/Actions', $this->fixtures.'/Discovery/Toolsets']), 'Toolsets');

        expect($findings)->toBe([
            ['warn', 'Toolsets', 'Tests\Fixtures\Discovery\Toolsets\StaffNote: #[Expose(agents: …)] names the [support] toolset, which no agent uses.'],
            ['warn', 'Toolsets', 'Tests\Fixtures\Discovery\Toolsets\SupportNote: #[Expose(agents: …)] names the [support] toolset, which no agent uses.'],
        ]);
    });

    it('warns about a toolset larger than agents.max_tools_per_toolset', function () {
        $this->skipUnlessAi();

        config(['agentic-actions.agents.max_tools_per_toolset' => 1]);

        expect(inRow(findingsFor([CreateNote::class, ListNotes::class]), 'Toolsets'))->toBe([
            ['warn', 'Toolsets', 'The [default] toolset holds 2 actions, more than agents.max_tools_per_toolset (1). An agent reads every tool on every turn: split the toolset, or raise the limit.'],
        ]);
    });

    it('warns about an agent whose own tools() replaces the trait\'s and never calls actionTools()', function () {
        $this->skipUnlessAi();

        expect(inRow(findingsFor([CreateNote::class, OwnToolsAgent::class]), 'Toolsets'))->toBe([
            ['warn', 'Toolsets', OwnToolsAgent::class.': it declares its own tools(), which replaces the one InteractsWithActions gives it, and that tools() never calls $this->actionTools(), so the agent receives none of its toolsets\' actions. Delete the tools() it declares, or merge the package\'s tools into it: return [...$this->actionTools(), ...].'],
        ]);
    });

    it('passes an agent on the trait\'s tools(), and one whose own tools() spreads actionTools()', function () {
        $this->skipUnlessAi();

        expect(inRow(findingsFor([CreateNote::class, NotesAgent::class, MixedAgent::class]), 'Toolsets'))->toBe([]);
    });
});

describe('route rows', function () {
    it('fails an action with a generated route and a hand-written one', function () {
        $this->mountRoutes(function () {
            Route::middleware('auth')->group(fn () => $this->generatedRoute(CreateNote::class));
            Route::post('notes', CreateNote::class)->name('notes.store');
        });

        expect(inRow(findingsFor([CreateNote::class]), 'Routes'))->toBe([
            ['fail', 'Routes', CreateNote::class.' has a generated route and a hand-written one [notes.store]: keep one.'],
        ]);
    });

    it('fails cached routes that no longer match the actions on disk', function () {
        // What routesAreCached() memoizes once it has seen a route cache file.
        $this->app->instance('routes.cached', true);

        try {
            $this->mountRoutes(fn () => Route::middleware('auth')->group(function () {
                $this->generatedRoute(CreateNote::class);
                $this->generatedRoute(PlainNote::class);
            }));

            $findings = inRow(findingsFor([CreateNote::class, ListNotes::class, PlainNote::class]), 'Routes');
        } finally {
            $this->app->instance('routes.cached', false);
        }

        expect($this->app->routesAreCached())->toBeFalse()
            ->and($findings)->toBe([
                ['fail', 'Routes', 'The cached route [plain-note] serves '.PlainNote::class.', which the actions on disk no longer open on the web: run php artisan route:cache again.'],
                ['fail', 'Routes', ListNotes::class.' opens on the web but has no cached generated route: run php artisan route:cache again.'],
            ]);
    });

    it('warns about a generated route outside any auth middleware', function () {
        $this->mountRoutes(function () {
            Route::middleware('web')->prefix('open')->name('open.')->group(fn () => $this->generatedRoute(CreateNote::class));
            Route::middleware('auth')->prefix('web')->name('web.')->group(fn () => $this->generatedRoute(CreateNote::class));
            Route::middleware('auth:sanctum')->prefix('api')->name('api.')->group(fn () => $this->generatedRoute(CreateNote::class));
            Route::middleware(Authenticate::class.':web')->prefix('class')->name('class.')->group(fn () => $this->generatedRoute(CreateNote::class));
        });

        expect(inRow(findingsFor([CreateNote::class]), 'Auth group'))->toBe([
            ['warn', 'Auth group', "The generated route [open.create-note] has no auth middleware. It still refuses guests, but mount Actions::routes() inside your own auth group, such as Route::middleware('auth')."],
        ]);
    });

    it('reads auth through a middleware group of the app\'s own', function () {
        app('router')->middlewareGroup('signed-in', ['web', 'auth']);

        $this->mountRoutes(fn () => Route::middleware('signed-in')->group(fn () => $this->generatedRoute(CreateNote::class)));

        expect(inRow(findingsFor([CreateNote::class]), 'Auth group'))->toBe([]);
    });
});

describe('agent rows', function () {
    beforeEach(function () {
        $this->skipUnlessAi();
    });

    it('fails a forbidden key offered to agents, naming the pattern and the recipe', function () {
        expect(inRow(findingsFor([ForbiddenSecret::class]), 'Model input'))->toBe([
            ['fail', 'Model input', ForbiddenSecret::class.': agents cannot be offered input [client_secret]: it matches agents.forbidden_keys pattern "*secret*". Remove the key from schema(), or follow '.STRICT_RECIPE],
        ]);
    });

    it('fails a key that is a route parameter of the action', function () {
        $this->mountRoutes(fn () => Route::post('notes/{title}', CreateNote::class)->name('notes.titled'));

        expect(inRow(findingsFor([CreateNote::class]), 'Model input'))->toBe([
            ['fail', 'Model input', CreateNote::class.': agents cannot be offered input [title]: it is a route parameter of notes.titled. Remove the key from schema(), or follow '.STRICT_RECIPE],
        ]);
    });

    it('fails a rules() key that schema() does not declare', function () {
        expect(inRow(findingsFor([RulesOnlyAgentKey::class]), 'Model input'))->toBe([
            ['fail', 'Model input', RulesOnlyAgentKey::class.': rules() checks [team_id], which schema() does not declare, so agents can never send it. Declare it in schema(), drop the rule, or follow '.STRICT_RECIPE],
        ]);
    });

    it('fails an output key agents may never receive', function () {
        config(['agentic-actions.agents.forbidden_output_keys' => ['title']]);

        expect(inRow(findingsFor([CreateNote::class]), 'Model output'))->toBe([
            ['fail', 'Model output', CreateNote::class.': agents cannot receive output [title]: it matches agents.forbidden_output_keys. Remove the key from outputSchema().'],
        ]);
    });

    it('fails an id offered to agents without an input-aware authorize()', function () {
        expect(inRow(findingsFor([IdEarlyAuthorize::class]), 'Ids'))->toBe([
            ['fail', 'Ids', IdEarlyAuthorize::class.': agents are offered [post_id], which looks like an id, and authorize() does not take ValidatedInput, so the record is never checked before handle(). Check it in authorize(ActionContext $context, ValidatedInput $input), or follow '.STRICT_RECIPE],
        ]);
    });

    it('fails an unscoped exists rule on an id, and passes a scoped one', function () {
        $findings = findingsFor([IdUnscopedExists::class, IdScopedExists::class]);

        expect(inRow($findings, 'Ids'))->toBe([
            ['fail', 'Ids', IdUnscopedExists::class.': rules() checks [post_id] with an unscoped exists rule, which tells any caller whether a record exists in any tenant. Scope it to the actor or tenant (Rule::exists(...)->where(...)), or follow '.STRICT_RECIPE],
        ])
            ->and(inRow($findings, 'Oracles'))->toBe([
                ['warn', 'Oracles', IdScopedExists::class.': rules() checks [post_id] with an exists rule, so any caller who reaches the action learns whether a matching record exists.'],
            ]);
    });

    it('fails fromAgent() in front of an input-taking authorize() without shouldRegister()', function () {
        expect(inRow(findingsFor([OrderWithoutShouldRegister::class]), 'Order'))->toBe([
            ['fail', 'Order', OrderWithoutShouldRegister::class.': fromAgent() runs before authorize(), which takes ValidatedInput, and shouldRegister() is not overridden, so any caller who reaches the tool runs fromAgent() unchecked. Override shouldRegister(), or check the actor in an input-free authorize().'],
        ]);
    });

    it('fails a file field offered to agents', function () {
        expect(inRow(findingsFor([FileForAgents::class]), 'Schema'))->toBe([
            ['fail', 'Schema', FileForAgents::class.': agents cannot send the file field [attachment]. Give agents an agentSchema() without it, following the "Strict agent schemas (no ids)" recipe (https://agentic-actions.com/recipes#strict-agent-schemas-no-ids).'],
        ]);
    });

    it('warns about Laravel 13\'s #[Middleware] on an agent-exposed class', function () {
        if (! class_exists(Middleware::class)) {
            $this->markTestSkipped('Laravel 12 has no controller attributes.');
        }

        expect(inRow(findingsFor([HttpOnlyAttributes::class]), 'HTTP-only'))->toBe([
            ['warn', 'HTTP-only', HttpOnlyAttributes::class.': #[Middleware] runs on HTTP only, so agents never pass through it. Check the same thing in authorize() or shouldRegister().'],
        ]);
    });
});

// Outside the agent rows, whose skipUnlessAi() would keep it from the cell without laravel/ai.
describe('model rows without laravel/ai', function () {
    it('runs the model rows for an action MCP opens, while laravel/ai is missing', function () {
        $this->usePackages(['laravel/ai' => PackageStatus::Missing]);

        $findings = findingsFor([ForbiddenSecret::class, IdEarlyAuthorize::class, HttpOnlyAttributes::class]);

        // Laravel 12 has no #[Middleware], so the HTTP-only row has nothing to name there.
        expect(inRow($findings, 'Model input'))->toHaveCount(1)
            ->and(inRow($findings, 'Ids'))->toHaveCount(1)
            ->and(inRow($findings, 'HTTP-only'))->toHaveCount(class_exists(Middleware::class) ? 1 : 0);
    });
});

describe('schema rows', function () {
    it('fails a non-empty outputSchema() with no required key', function () {
        expect(inRow(findingsFor([EmptyRequiredOutput::class]), 'Output'))->toBe([
            ['fail', 'Output', EmptyRequiredOutput::class.': outputSchema() marks no key required, so a caller cannot rely on any key being there. Mark the keys handle() always returns with required().'],
        ]);
    });

    it('fails a schema the rule compiler refuses', function () {
        $findings = inRow(findingsFor([UnsupportedUnion::class]), 'Schema');

        expect($findings)->toHaveCount(1)
            ->and($findings[0][0])->toBe('fail')
            ->and($findings[0][2])->toStartWith(UnsupportedUnion::class.': schema(): [reference] ');
    });

    it('warns once when rules() needs a context, and skips the rows that read it', function () {
        expect(findingsFor([RulesNeedActor::class]))->toBe([
            ['warn', 'Oracles', RulesNeedActor::class.': rules() needs a context; skipped'],
        ]);
    });
});

describe('other rows', function () {
    it('warns when the installed npm client\'s major.minor differs from the PHP package\'s', function (string $php, string $npm, bool $warns) {
        $this->usePackages(['agentic-actions/laravel' => $php]);

        $file = base_path('node_modules/@agentic-actions/client/package.json');
        $existed = is_dir(base_path('node_modules'));

        File::ensureDirectoryExists(dirname($file));
        File::put($file, json_encode(['name' => '@agentic-actions/client', 'version' => $npm]));

        try {
            $findings = inRow(findingsFor([CreateNote::class]), 'npm');
        } finally {
            File::deleteDirectory($existed ? base_path('node_modules/@agentic-actions') : base_path('node_modules'));
        }

        expect($findings)->toBe($warns
            ? [['warn', 'npm', "@agentic-actions/client {$npm} is installed, but agentic-actions/laravel is {$php}: run npm install to update the client."]]
            : []);
    })->with([
        'a newer minor' => ['0.1.0', '0.2.0', true],
        'the same minor' => ['0.1.0', '0.1.3', false],
        'a dev-main checkout' => ['dev-main', '0.2.0', false],
    ]);

    it('has no npm row without an installed client', function () {
        expect(inRow(findingsFor([CreateNote::class]), 'npm'))->toBe([]);
    });

    it('requires a manifest that matches the scan with --production', function () {
        config(['agentic-actions.discovery.paths' => [], 'agentic-actions.discovery.classes' => [CreateNote::class]]);

        $missing = inRow(findingsFor([CreateNote::class], [], production: true), 'Manifest');

        $this->artisan('actions:cache')->assertSuccessful();

        $fresh = inRow(findingsFor([CreateNote::class], [], production: true), 'Manifest');
        $without = inRow(findingsFor([CreateNote::class]), 'Manifest');
        $differs = inRow(findingsFor([CreateNote::class, ListNotes::class], [], production: true), 'Manifest');

        expect($missing)->toBe([['fail', 'Manifest', 'The actions manifest is missing: run php artisan optimize, or actions:cache, on deploy.']])
            ->and($fresh)->toBe([])
            ->and($without)->toBe([])
            ->and($differs)->toBe([['fail', 'Manifest', 'The actions manifest differs from the actions on disk: run php artisan actions:cache.']]);
    });
});

describe('the command', function () {
    it('prints failures and warnings with their rows, and exits 1 on any failure', function () {
        config(['agentic-actions.discovery.paths' => [], 'agentic-actions.discovery.classes' => [NoAuthorize::class, IdScopedExists::class]]);

        checkActionsCommand(['--update' => true]);

        [$code, $output] = checkActionsCommand();

        expect($code)->toBe(1)
            ->and($output)->toContain(
                '  ✗ [Authorization] '.NoAuthorize::class.': authorize() is missing',
                '  ! [Oracles] '.IdScopedExists::class.': rules() checks [post_id]',
                '1 failure, 1 warning.',
            );
    });

    it('exits 0 with warnings only', function () {
        checkActionsCommand(['--update' => true]);

        [$code, $output] = checkActionsCommand();

        expect($code)->toBe(0)
            ->and($output)->toContain('! [Oracles] '.LateAuthorize::class)
            ->and($output)->toContain('Every check passed, with 1 warning.');
    });

    it('fails without a manifest with --production', function () {
        checkActionsCommand(['--update' => true]);

        [$code, $output] = checkActionsCommand(['--production' => true]);

        expect($code)->toBe(1)->and($output)->toContain('✗ [Manifest] The actions manifest is missing');
    });

    it('writes nothing with --update when two actions share a name', function () {
        config(['agentic-actions.discovery.paths' => [$this->fixtures.'/Misconfigured']]);

        [$code, $output] = checkActionsCommand(['--update' => true]);

        expect($code)->toBe(1)
            ->and($this->snapshot)->not->toBeFile()
            ->and($output)->toContain('The snapshot was not written', '✗ [Names]');
    });
});

describe('tables & datasets', function () {
    beforeEach(function () {
        BadColumns::$columns = null;
    });

    it('reports columns a table cannot show, with their message, and the other rows still run', function (Closure $columns, string $message) {
        BadColumns::$columns = $columns;

        $findings = findingsFor([BadColumns::class]);

        expect(inRow($findings, 'Tables & datasets'))->toBe([['fail', 'Tables & datasets', BadColumns::class.': '.$message]])
            ->and(inRow($findings, 'Model output'))->toBe([]);
    })->with([
        'a key that is not snake case' => [fn (): array => [Column::text('Title', 'Title')], 'The column key [Title] must be 1 to 64 lower-case letters, digits or underscores, starting with a letter.'],
        'a key given twice' => [fn (): array => [Column::text('title', 'Title'), Column::integer('title', 'Words')], 'columns() declares the key [title] twice.'],
        'a currency that is not ISO 4217' => [fn (): array => [Column::money('price', 'Price', 'dollars')], 'The column [price] has the currency [dollars]: give its ISO 4217 code, three upper-case letters such as USD.'],
    ]);

    it('fails a ShowsTable action that is not a Read, and passes a table that is', function () {
        expect(inRow(findingsFor([WriteWithTable::class, PostStats::class]), 'Tables & datasets'))->toBe([
            ['fail', 'Tables & datasets', WriteWithTable::class.': it implements ShowsTable, but its effect is write, so it shows no table: a table is a Read action\'s. Declare Effect::Read, or remove ShowsTable.'],
        ]);
    });

    it('reads a table\'s column keys against agents.forbidden_output_keys', function () {
        config(['agentic-actions.agents.forbidden_output_keys' => ['author']]);

        expect(inRow(findingsFor([PostStats::class]), 'Model output'))->toBe([
            ['fail', 'Model output', PostStats::class.': agents cannot receive output [author]: it matches agents.forbidden_output_keys. Remove the column from columns().'],
        ]);
    });

    it('never reads a table\'s own keys against agents.forbidden_output_keys', function () {
        config(['agentic-actions.agents.forbidden_output_keys' => ['*key*', 'label', 'type', 'columns', 'rows', 'chart', 'caption', 'truncated']]);

        expect(inRow(findingsFor([PostStats::class]), 'Model output'))->toBe([]);
    });
});
