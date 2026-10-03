<?php

namespace Tests\Workbench;

use AgenticActions\Support\Packages;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Tests\TestCase;
use Workbench\App\Providers\WorkbenchServiceProvider;

abstract class WorkbenchTestCase extends TestCase
{
    use WithWorkbench;

    /**
     * The package's providers plus the workbench's. testbench.yaml's "providers" never reach a test.
     *
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), WorkbenchServiceProvider::class];
    }

    /**
     * The workbench's configuration over the suite's defaults. The package statuses and the pinned version stay the
     * suite's, so the committed TypeScript file's version stamp does not follow the checkout's branch. The web group's
     * cookies need an application key, which the suite never reads from a .env file.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        WorkbenchServiceProvider::configure($app);

        $app->instance(Packages::class, new Packages($this->packageDefaults()));
    }
}
