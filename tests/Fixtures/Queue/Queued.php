<?php

namespace Tests\Fixtures\Queue;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Workbench\App\Models\User;

/**
 * The queue tests' helpers: a person behind a real Sanctum token, the database queue, and one worker pass.
 */
final class Queued
{
    /**
     * A person acting through a real Sanctum token with these abilities, on the sanctum guard.
     *
     * @param  list<string>  $abilities
     */
    public static function tokenUser(array $abilities, ?User $user = null): User
    {
        $user ??= User::factory()->create();
        $user->withAccessToken($user->createToken('queue', $abilities)->accessToken);

        Auth::shouldUse('sanctum');

        return $user;
    }

    /**
     * Send jobs to the database queue, where they wait for a worker, and log failed jobs on the test's connection.
     */
    public static function onDatabase(): void
    {
        config(['queue.default' => 'database', 'queue.failed.database' => config('database.default')]);

        app()->forgetInstance('queue.failer');
    }

    /**
     * Run one job from the database queue in this process, as `queue:work --once` does in a worker.
     */
    public static function work(): void
    {
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
    }
}
