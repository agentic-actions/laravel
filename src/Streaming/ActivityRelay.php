<?php

namespace AgenticActions\Streaming;

use AgenticActions\Ai\ActionTool;
use AgenticActions\Contracts\DescribesActivity;
use Closure;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Tools\ToolNameResolver;
use Throwable;

/**
 * Writes a data-action row when a tool of the streamed run starts and when it ends. Never throws: a failure here is
 * reported, and the tool and the turn carry on.
 *
 * @internal
 */
final class ActivityRelay
{
    /**
     * A tool of the streamed run is about to run: read both labels now, and write the running row.
     */
    public function invoking(InvokingTool $event): void
    {
        $protocol = ActionsProtocol::current($event->invocationId);
        $tool = $event->tool;

        if ($protocol === null || ! $tool instanceof DescribesActivity) {
            return;
        }

        $this->safely(function () use ($protocol, $tool, $event): void {
            if (($running = $tool->activityLabel(false)) === null) {
                return;
            }

            $protocol->relay($protocol->start(
                $event->toolInvocationId,
                ToolNameResolver::resolve($tool),
                $running,
                $tool->activityLabel(true),
                $tool instanceof ActionTool ? $tool->entry()->effect : null,
            ));
        });
    }

    /**
     * A tool returned: write its row with the status it reported.
     */
    public function invoked(ToolInvoked $event): void
    {
        $this->close($event->invocationId, $event->toolInvocationId, threw: false);
    }

    /**
     * A tool threw: write its row as failed.
     */
    public function failed(ToolFailed $event): void
    {
        $this->close($event->invocationId, $event->toolInvocationId, threw: true);
    }

    /**
     * Close the row of a tool of the streamed run, if one is open: its row, then the table it showed.
     */
    private function close(string $invocationId, string $toolInvocationId, bool $threw): void
    {
        $protocol = ActionsProtocol::current($invocationId);

        if ($protocol === null) {
            return;
        }

        $this->safely(function () use ($protocol, $toolInvocationId, $threw): void {
            foreach ($protocol->finish($toolInvocationId, $threw) as $part) {
                $protocol->relay($part);
            }
        });
    }

    /**
     * Run a step of the relay, reporting anything it throws. A row that fails to close closes as ended at stream end.
     */
    private function safely(Closure $work): void
    {
        try {
            $work();
        } catch (Throwable $exception) {
            try {
                report($exception);
            } catch (Throwable) {
                // A reporter that throws must not reach the tool loop.
            }
        }
    }
}
