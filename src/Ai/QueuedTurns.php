<?php

namespace AgenticActions\Ai;

use AgenticActions\ActionContext;
use AgenticActions\Queue\RunAction;
use AgenticActions\Security\TokenCheck;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Jobs\BroadcastAgent;
use Laravel\Ai\Jobs\InvokeAgent;

/**
 * The grants of the credential that queued a laravel/ai agent turn, kept on the job and read in the worker.
 *
 * A turn laravel/ai queues (`queue()`, `broadcastOnQueue()`) builds its tools in the worker, where no guard holds the
 * caller's token and a session guard reads as no limits. So the job's payload keeps what the caller's token granted
 * when the turn was queued, and while the worker runs that job, TokenCheck reads those grants, as it reads a queued
 * run's. A turn queued by a session, the console or a guest keeps none, and runs as the person, as before.
 *
 * @internal
 */
final class QueuedTurns
{
    /**
     * The payload key the grants ride under.
     */
    public const KEY = 'agenticActionsGrants';

    /**
     * laravel/ai's jobs that run an agent's turn.
     */
    private const JOBS = [InvokeAgent::class, BroadcastAgent::class];

    /**
     * The grants of the turn the worker is running, or null while it runs none that kept any.
     *
     * @var list<string>|null
     */
    private static ?array $current = null;

    /**
     * Keep the grants on each queued turn, and read them back while a worker runs one. Called once per boot: the payload
     * hook is static on the queue, an app boots its providers once, and Laravel's tests clear the hooks between tests.
     */
    public static function register(Dispatcher $events): void
    {
        Queue::createPayloadUsing(static fn (string $connection, ?string $queue, array $payload): array => self::kept($payload));

        $events->listen(JobProcessing::class, static function (JobProcessing $event): void {
            $grants = $event->job->payload()[self::KEY] ?? null;

            self::$current = is_array($grants) ? array_values(array_filter($grants, is_string(...))) : null;
        });

        $events->listen([JobProcessed::class, JobExceptionOccurred::class, JobFailed::class], static function (): void {
            self::$current = null;
        });
    }

    /**
     * The grants of the turn the worker is running, or null when it runs none that kept any.
     *
     * @return list<string>|null
     */
    public static function grants(): ?array
    {
        return self::$current;
    }

    /**
     * What a laravel/ai turn's payload keeps: the grants its caller had, from a queued run or turn the call came from,
     * else from the token the request was authenticated with. Nothing for a session, the console or a guest.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>
     */
    private static function kept(array $payload): array
    {
        // While the hooks run, the payload still holds the job itself; the queue writes its class and serialized
        // form after them.
        $job = is_array($payload['data'] ?? null) ? ($payload['data']['command'] ?? null) : null;

        if (! is_object($job) || ! in_array($job::class, self::JOBS, true)) {
            return [];
        }

        $grants = match (true) {
            RunAction::running() !== null => RunAction::running()->grants,
            self::$current !== null => self::$current,
            ($user = Auth::user()) !== null => app(TokenCheck::class)->capture(ActionContext::http($user)),
            default => null,
        };

        return $grants === null ? [] : [self::KEY => $grants];
    }
}
