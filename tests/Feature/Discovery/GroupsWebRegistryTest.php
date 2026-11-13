<?php

use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Discovery\Manifest;
use AgenticActions\Discovery\ManifestWriter;
use AgenticActions\Exceptions\MisconfiguredDiscovery;
use AgenticActions\Exceptions\StaleActionManifest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\Discovery\Groups\Billing\ChargeCard;
use Tests\Fixtures\Discovery\Groups\Billing\RefundCharge;

/*
 * discovery.groups in a web process, where production reads the manifest instead of scanning. A manifest written
 * while a module was installed must not keep nominating it once the module is gone, even though its classes still
 * autoload; and a misconfigured group must nominate nothing instead of failing every request. Each application boots
 * as a web process, as in WebRegistryTest.
 */

beforeAll(function () {
    $_SERVER['APP_RUNNING_IN_CONSOLE'] = $_ENV['APP_RUNNING_IN_CONSOLE'] = 'false';
    $_SERVER['APP_ROUTES_CACHE'] = $_ENV['APP_ROUTES_CACHE'] = sys_get_temp_dir().'/agentic-actions-group-routes-'.getmypid().'.php';
});

afterAll(function () {
    unset($_SERVER['APP_RUNNING_IN_CONSOLE'], $_ENV['APP_RUNNING_IN_CONSOLE'], $_SERVER['APP_ROUTES_CACHE'], $_ENV['APP_ROUTES_CACHE']);
});

beforeEach(function () {
    // Every group fixture offers agents, an exposure error without laravel/ai.
    $this->skipUnlessAi();

    config(['cache.default' => 'array']);
    Cache::forget('agentic-actions:manifest-missing');
    Cache::forget('agentic-actions:misconfigured-discovery');

    $this->fixtures = dirname(__DIR__, 2).'/Fixtures/Discovery/Groups';
    $this->root = 'agentic-group-web-'.getmypid();
    $this->manifest = Manifest::path($this->app);

    File::delete($this->manifest);
    File::copyDirectory($this->fixtures.'/Core', base_path($this->root.'/app'));
    File::copyDirectory($this->fixtures.'/Billing', base_path($this->root.'/modules/Billing/src'));

    config(['agentic-actions.discovery.groups' => [
        'core' => [
            'paths' => [$this->root.'/app'],
            'snapshot' => $this->root.'/actions.exposure.json',
        ],
        'billing' => [
            'paths' => [$this->root.'/modules/Billing/src'],
            // A stale config still lists the module's classes, which still autoload.
            'classes' => [ChargeCard::class, RefundCharge::class],
            'snapshot' => $this->root.'/modules/Billing/actions.exposure.json',
            'optional' => true,
        ],
    ]]);
});

afterEach(function () {
    if (! isset($this->root)) {
        return;
    }

    File::delete($this->manifest);
    File::deleteDirectory(base_path($this->root));

    // Teardown runs commands that ask for confirmation in production.
    $this->app['env'] = 'testing';
});

it('reads a manifest written for the groups installed now', function () {
    app(ManifestWriter::class)->write();
    $this->app['env'] = 'production';

    // From here on a scan would find nothing, so a registry that holds the actions read them from the manifest.
    File::cleanDirectory(base_path($this->root.'/app'));

    expect(array_keys(ActionRegistry::load($this->app)->all()))->toBe(['charge-card', 'refund-charge', 'rename-team'])
        ->and(Manifest::readFresh($this->app))->toHaveKey('groups');
});

it('ignores a manifest written while a module that is gone now was installed, though its classes still autoload', function () {
    Exceptions::fake();
    app(ManifestWriter::class)->write();

    File::deleteDirectory(base_path($this->root.'/modules'));
    $this->app['env'] = 'production';

    $registry = ActionRegistry::load($this->app);

    expect(class_exists(ChargeCard::class))->toBeTrue()
        ->and(Manifest::readFresh($this->app))->toBeNull()
        ->and(array_keys($registry->all()))->toBe(['rename-team'])
        ->and($registry->find('charge-card'))->toBeNull()
        ->and($registry->find(RefundCharge::class))->toBeNull()
        ->and($registry->agents())->toBe([]);

    Exceptions::assertReported(StaleActionManifest::class);

    // actions:cache on the deploy writes a manifest for the groups installed now, which is read again.
    app(ManifestWriter::class)->write();

    expect(array_keys(ActionRegistry::load($this->app)->all()))->toBe(['rename-team'])
        ->and(Manifest::readFresh($this->app))->not->toBeNull();
});

it('ignores a manifest written for other groups, or without any', function (Closure $change) {
    app(ManifestWriter::class)->write();

    $change($this);
    $this->app['env'] = 'production';

    expect(Manifest::readFresh($this->app))->toBeNull();
})->with([
    'a module added' => [function ($test): void {
        File::copyDirectory($test->fixtures.'/Clash', base_path($test->root.'/modules/Clash/src'));
        config(['agentic-actions.discovery.groups.clash' => ['paths' => [$test->root.'/modules/Clash/src'], 'snapshot' => $test->root.'/clash.json', 'optional' => true]]);
    }],
    'a path changed' => [fn ($test) => config(['agentic-actions.discovery.groups.billing.paths' => [$test->root.'/modules/Billing']])],
    'groups removed' => [fn ($test) => config(['agentic-actions.discovery.groups' => []])],
    'a group made optional' => [fn ($test) => config(['agentic-actions.discovery.groups.core.optional' => true])],
    'a class listed' => [fn ($test) => config(['agentic-actions.discovery.groups.core.classes' => [ChargeCard::class]])],
    'paths that are not a list' => [fn ($test) => config(['agentic-actions.discovery.groups.core.paths' => $test->root.'/app'])],
    'a value that is not configuration' => [fn ($test) => config(['agentic-actions.discovery.groups.core.classes' => [fn () => ChargeCard::class]])],
]);

it('ignores a manifest written without groups once groups are set', function () {
    config(['agentic-actions.discovery.groups' => []]);
    app(ManifestWriter::class)->write();

    expect(require $this->manifest)->not->toHaveKey('groups');

    config(['agentic-actions.discovery.groups' => ['core' => ['paths' => [$this->root.'/app'], 'snapshot' => $this->root.'/a.json']]]);
    $this->app['env'] = 'production';

    expect(Manifest::readFresh($this->app))->toBeNull();
});

it('nominates nothing for a misconfigured group in production, and reports it once an hour', function (array $group, string $message) {
    Exceptions::fake();
    app(ManifestWriter::class)->write();
    config(['agentic-actions.discovery.groups.billing' => $group]);
    $this->app['env'] = 'production';

    $registry = ActionRegistry::load($this->app);
    ActionRegistry::load($this->app);

    expect($registry->all())->toBe([])
        ->and($registry->agents())->toBe([]);

    // Once each: the misconfiguration, and the manifest it made unreadable.
    Exceptions::assertReported(fn (MisconfiguredDiscovery $exception): bool => str_contains($exception->getMessage(), $message));
    Exceptions::assertReported(StaleActionManifest::class);
    Exceptions::assertReportedCount(2);
})->with([
    'a path outside' => [['paths' => ['../elsewhere'], 'snapshot' => 'b.json'], 'discovery.groups.billing: paths [../elsewhere] steps out'],
    'an overlap' => [fn () => ['paths' => [test()->root.'/app'], 'snapshot' => 'b.json'], 'is also found by discovery.groups.core'],
]);

it('throws for a misconfigured group on a local request', function () {
    config(['agentic-actions.discovery.groups.billing.paths' => ['../elsewhere']]);
    $this->app['env'] = 'local';

    expect(fn () => ActionRegistry::load($this->app))->toThrow(MisconfiguredDiscovery::class, 'steps out');
});
