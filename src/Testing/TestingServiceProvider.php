<?php

namespace AgenticActions\Testing;

use Illuminate\Support\ServiceProvider;

/**
 * The testing kit: the Pest expectations, registered only while the app runs its tests.
 *
 * @internal
 */
final class TestingServiceProvider extends ServiceProvider
{
    /**
     * Register the Pest expectations when the app runs its tests and Pest is loaded. The kit binds nothing:
     * Actions::fake() binds the fake when a test asks for it.
     */
    public function boot(): void
    {
        if ($this->app->runningUnitTests()) {
            Expectations::register();
        }
    }
}
