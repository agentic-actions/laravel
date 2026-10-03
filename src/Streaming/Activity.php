<?php

namespace AgenticActions\Streaming;

use AgenticActions\Outcome;
use Closure;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * Lets a hand-written laravel/ai tool report what it did, so its copilot row shows done, refused or failed instead of
 * ended. Silent outside a streamed turn, and for a tool that shows no row. Never throws.
 *
 * @api
 */
final class Activity
{
    /**
     * Report the tool's own outcome. Touches count only when it succeeded; empty reloads nothing.
     *
     * @param  list<string>  $touches
     */
    public static function record(Request $request, bool $ok, array $touches = [], bool $crashed = false): void
    {
        self::put($request, fn (): ActivityRecord => new ActivityRecord(
            $ok ? 'done' : ($crashed ? 'failed' : 'refused'),
            $ok ? array_values($touches) : [],
        ));
    }

    /**
     * Report the outcome of an action the tool ran, for example through Actions::attempt().
     *
     * @param  array<string, mixed>|null  $view  @internal the data-view part of the table the call shows
     */
    public static function outcome(Request $request, Outcome $outcome, ?array $view = null): void
    {
        self::put($request, fn (): ActivityRecord => ActivityRecord::fromOutcome($outcome, $view));
    }

    /**
     * Hand the record to the streaming protocol for this tool invocation's open row.
     *
     * @param  Closure(): ActivityRecord  $record
     */
    private static function put(Request $request, Closure $record): void
    {
        try {
            $toolInvocationId = $request->toolInvocationId();

            if ($toolInvocationId !== null) {
                ActionsProtocol::current()?->record($toolInvocationId, $record);
            }
        } catch (Throwable $exception) {
            try {
                report($exception);
            } catch (Throwable) {
                // A reporter that throws must not turn a committed write into a failed call, as in the relay's safely().
            }
        }
    }
}
