<?php

namespace AgenticActions\Streaming;

use AgenticActions\Ai\PendingCards;
use AgenticActions\Ai\QueuedTurns;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;

/**
 * Registers the activity relay and the confirmation cards when laravel/ai is installed.
 *
 * @internal
 */
final class StreamingServiceProvider extends ServiceProvider
{
    /**
     * Keep one set of previewed cards per request or job. Without laravel/ai nothing pauses.
     */
    public function register(): void
    {
        if (interface_exists(Tool::class)) {
            $this->app->scoped(PendingCards::class);
        }
    }

    /**
     * Listen for laravel/ai's tool events and its pauses. Without laravel/ai there is nothing to listen to.
     */
    public function boot(): void
    {
        if (! interface_exists(Tool::class)) {
            return;
        }

        $events = $this->app->make(Dispatcher::class);

        $events->listen(InvokingTool::class, [ActivityRelay::class, 'invoking']);
        $events->listen(ToolInvoked::class, [ActivityRelay::class, 'invoked']);
        $events->listen(ToolFailed::class, [ActivityRelay::class, 'failed']);
        // The cards ActionTool previewed live in the current request's container. Under Octane that is a copy of the
        // worker's application, and the dispatcher would resolve a class listener from the worker's own, empty one.
        $events->listen(ToolApprovalRequested::class, fn (ToolApprovalRequested $event) => app(PendingCards::class)->requested($event));

        // A turn laravel/ai queues keeps the grants of the token that queued it.
        QueuedTurns::register($events);
    }
}
