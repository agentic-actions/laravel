<?php

use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Discovery\Manifest;
use AgenticActions\Discovery\ManifestWriter;
use AgenticActions\Exceptions\DuplicateActionName;
use AgenticActions\Exceptions\MisconfiguredExposure;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Support\PackageStatus;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Misconfigured\NoDescriptionForAgents;
use Tests\Fixtures\Misconfigured\UndeclaredEffect;

use function Orchestra\Testbench\remote;

beforeEach(function () {
    $this->fixtures = dirname(__DIR__, 2).'/Fixtures';
    $this->manifest = Manifest::path($this->app);
    $this->routes = sys_get_temp_dir().'/agentic-actions-routes-'.getmypid().'.php';
    $this->services = sys_get_temp_dir().'/agentic-actions-services-'.getmypid().'.php';

    File::delete([$this->manifest, $this->routes, $this->services]);
});

afterEach(function () {
    File::delete([$this->manifest, $this->routes, $this->services]);
});

/**
 * The manifest on disk.
 *
 * @return array<string, mixed>
 */
function manifestOnDisk(): array
{
    $manifest = require Manifest::path(app());

    expect($manifest)->toBeArray();

    return $manifest;
}

describe('actions:cache', function () {
    it('writes a var_export manifest through a temporary file and rename()', function () {
        File::put($this->manifest, 'left by an older release');
        $inode = fileinode($this->manifest);
        $count = count(app(ActionRegistry::class)->all());

        $this->artisan('actions:cache')
            ->expectsOutputToContain("Actions cached: {$count}.")
            ->assertSuccessful()
            ->run();

        clearstatcache();
        $manifest = manifestOnDisk();

        expect(fileinode($this->manifest))->not->toBe($inode)
            ->and(File::get($this->manifest))->toStartWith('<?php return array (')
            ->and(glob($this->manifest.'?*'))->toBe([])
            ->and(fileperms($this->manifest) & 0111)->toBe(0)
            ->and($manifest['version'])->toBe(ActionRegistry::VERSION)
            // The upgrade notes promise the manifest stays version 5, so an older release's manifest still loads.
            ->and(ActionRegistry::VERSION)->toBe(5)
            ->and(array_keys($manifest['actions']))->toBe(array_keys(app(ActionRegistry::class)->all()))
            ->and($manifest['actions']['create-note'])->toBe(ClassExposure::of(CreateNote::class)->toManifest())
            ->and($manifest['actions']['create-note']['surfaces'])->toContain('mcp')
            ->and($manifest['agents'])->toBe([]);
    });

    it('writes an empty manifest when nothing is found', function () {
        config(['agentic-actions.discovery.paths' => [$this->fixtures.'/Discovery/Plain']]);

        $this->artisan('actions:cache')->expectsOutputToContain('Actions cached: 0.')->assertSuccessful()->run();

        expect(manifestOnDisk())->toBe(['version' => ActionRegistry::VERSION, 'actions' => [], 'agents' => []]);
    });

    it('records the agents it scanned', function () {
        config(['agentic-actions.discovery.paths' => [$this->fixtures.'/Discovery/Agents']]);

        app(ManifestWriter::class)->write();

        expect(manifestOnDisk()['agents'])->toBe([
            'Tests\\Fixtures\\Discovery\\Agents\\BlogWriter' => ['default'],
            'Tests\\Fixtures\\Discovery\\Agents\\SupportDesk' => ['support'],
        ]);
    });

    it('throws MisconfiguredExposure for a misconfigured action on the path, and writes nothing', function () {
        config([
            'agentic-actions.discovery.paths' => [$this->fixtures.'/Actions'],
            'agentic-actions.discovery.classes' => [UndeclaredEffect::class, NoDescriptionForAgents::class],
        ]);

        expect(fn () => Artisan::call('actions:cache'))
            ->toThrow(MisconfiguredExposure::class, UndeclaredEffect::class.': http: effect undeclared');

        expect($this->manifest)->not->toBeFile();
    });

    it('throws DuplicateActionName for the misconfigured fixture directory, which holds two actions of one name', function () {
        config(['agentic-actions.discovery.paths' => [$this->fixtures.'/Misconfigured']]);

        expect(fn () => Artisan::call('actions:cache'))->toThrow(DuplicateActionName::class);

        expect($this->manifest)->not->toBeFile();
    });

    it('fails php artisan optimize on a misconfigured action', function () {
        config(['agentic-actions.discovery.classes' => [UndeclaredEffect::class]]);

        expect(ServiceProvider::$optimizeCommands['agentic-actions'] ?? null)->toBe('actions:cache');

        // Only the package's own task: the framework's would rewrite the Testbench skeleton's caches.
        expect(fn () => Artisan::call('optimize', ['--except' => 'config,events,routes,views']))
            ->toThrow(MisconfiguredExposure::class);

        expect($this->manifest)->not->toBeFile();
    });

    it('caches a bare #[Expose] with the agent surface skipped when laravel/ai is missing', function () {
        $this->usePackages(['laravel/ai' => PackageStatus::Missing]);

        $this->artisan('actions:cache')->assertSuccessful()->run();

        $row = manifestOnDisk()['actions']['create-note'];

        expect($row['surfaces'])->toBe(['console', 'http', 'mcp'])
            ->and($row['toolsets'])->toBe([])
            ->and($row['skipped'])->toBe(['agent' => 'laravel/ai is not installed']);
    });

    it('refuses to cache while laravel/ai is only a dev requirement', function () {
        $this->skipUnlessAi();
        $this->usePackages(['laravel/ai' => PackageStatus::DevOnly]);

        expect(fn () => Artisan::call('actions:cache'))
            ->toThrow(MisconfiguredExposure::class, CreateNote::class.': agent: laravel/ai is installed only as a dev requirement: move it to "require"');
    });
});

describe('actions:clear', function () {
    it('deletes the manifest, and succeeds when there is none', function () {
        app(ManifestWriter::class)->write();

        $this->artisan('actions:clear')->expectsOutputToContain('Actions manifest cleared.')->assertSuccessful()->run();

        expect($this->manifest)->not->toBeFile();

        $this->artisan('actions:clear')->expectsOutputToContain('Actions manifest cleared.')->assertSuccessful()->run();
    });

    it('runs inside optimize:clear', function () {
        app(ManifestWriter::class)->write();

        expect(ServiceProvider::$optimizeClearCommands['agentic-actions'] ?? null)->toBe('actions:clear');

        // Only the package's own task: the framework's would delete the Testbench skeleton's caches.
        $this->artisan('optimize:clear', ['--except' => 'config,cache,compiled,events,routes,views'])->assertSuccessful()->run();

        expect($this->manifest)->not->toBeFile();
    });
});

describe('route:cache and route:clear', function () {
    it('writes the manifest after a standalone route:cache, and removes it on a standalone route:clear', function () {
        // The workbench offers DeletePost and PublishPost to TeamAssistant's toolset, which needs laravel/ai.
        $this->skipUnlessAi();

        // A separate process, as on a server: route:cache builds a fresh application of its own, and Laravel fires
        // the console events only outside a test run. Its caches go to scratch files, never into the Testbench
        // skeleton.
        $env = ['APP_ENV' => 'local', 'APP_ROUTES_CACHE' => $this->routes, 'APP_SERVICES_CACHE' => $this->services];

        remote('route:cache', $env)->mustRun();

        expect($this->manifest)->toBeFile()
            ->and($this->routes)->toBeFile()
            ->and(manifestOnDisk()['version'])->toBe(ActionRegistry::VERSION)
            ->and(filemtime($this->manifest))->toBeGreaterThanOrEqual(filemtime($this->routes));

        remote('route:clear', $env)->mustRun();

        expect($this->manifest)->not->toBeFile()
            ->and($this->routes)->not->toBeFile();
    });

    it('writes nothing when route:cache fails', function () {
        event(new CommandFinished('route:cache', new ArrayInput([]), new NullOutput, 1));

        expect($this->manifest)->not->toBeFile();

        event(new CommandFinished('route:cache', new ArrayInput([]), new NullOutput, 0));

        expect($this->manifest)->toBeFile();
    });
});

describe('about', function () {
    it('shows the version, the count and whether the manifest is cached', function () {
        $count = count(app(ActionRegistry::class)->all());

        Artisan::call('about', ['--only' => 'agentic_actions']);
        $before = Artisan::output();

        app(ManifestWriter::class)->write();

        Artisan::call('about', ['--only' => 'agentic_actions']);
        $after = Artisan::output();

        expect($before)->toContain('Agentic Actions')
            ->and($before)->toMatch('/Version\s*\.+\s*0\.1\.0/')
            ->and($before)->toMatch("/Actions\s*\.+\s*{$count}\s*$/m")
            ->and($before)->toMatch('/Manifest\s*\.+\s*NOT CACHED/')
            ->and($after)->toMatch('/Manifest\s*\.+\s*CACHED/')
            ->and($after)->not->toContain('NOT CACHED');
    });
});
