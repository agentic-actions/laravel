<?php

use AgenticActions\Support\Packages;
use AgenticActions\Support\PackageStatus;
use Composer\InstalledVersions;
use Laravel\Ai\Contracts\Tool;

it('strips one leading "v" from an overridden version', function () {
    $packages = new Packages(['agentic-actions/laravel' => 'v0.1.0', 'other/package' => 'dev-main', 'odd/package' => 'vv1']);

    expect($packages->version('agentic-actions/laravel'))->toBe('0.1.0')
        ->and($packages->version('other/package'))->toBe('dev-main')
        ->and($packages->version('odd/package'))->toBe('vv1');
});

it('strips one leading "v" from Composer\'s pretty version', function () {
    $pretty = InstalledVersions::getPrettyVersion('laravel/framework');

    expect($pretty)->toStartWith('v')
        ->and((new Packages)->version('laravel/framework'))->toBe(substr((string) $pretty, 1));
});

it('has no version for a missing package', function () {
    expect((new Packages)->version('nobody/nothing'))->toBeNull();
});

it('tells missing, dev-only and installed packages apart', function () {
    $packages = new Packages;

    expect($packages->status('nobody/nothing'))->toBe(PackageStatus::Missing)
        ->and($packages->status('pestphp/pest'))->toBe(PackageStatus::DevOnly)
        ->and($packages->status('laravel/framework'))->toBe(PackageStatus::Installed)
        ->and((new Packages(['pestphp/pest' => PackageStatus::Installed]))->status('pestphp/pest'))->toBe(PackageStatus::Installed);
});

it('reports laravel/ai as missing when its Tool contract cannot load', function () {
    $packages = new Packages(['laravel/ai' => PackageStatus::Installed]);

    if (interface_exists(Tool::class)) {
        expect($packages->laravelAi())->toBe(PackageStatus::Installed)
            ->and((new Packages(['laravel/ai' => PackageStatus::DevOnly]))->laravelAi())->toBe(PackageStatus::DevOnly);

        return;
    }

    expect($packages->laravelAi())->toBe(PackageStatus::Missing);
});
