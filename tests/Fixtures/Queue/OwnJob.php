<?php

namespace Tests\Fixtures\Queue;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Refusal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Workbench\App\Models\User;

/**
 * A job of an app's own, as the "Your own jobs" recipe writes one: it runs an action with run() and a context it builds
 * with ActionContext::http(), and keeps each refusal instead of failing.
 */
final class OwnJob implements ShouldQueue
{
    use Queueable;

    /**
     * The refusals of its calls, in order.
     *
     * @var list<Refusal>
     */
    public static array $refusals = [];

    /**
     * Create the job.
     *
     * @param  class-string<Action>  $action
     */
    public function __construct(public User $person, public string $action) {}

    /**
     * Run the action as the person, with no input.
     */
    public function handle(): void
    {
        try {
            ($this->action)::run([], ActionContext::http($this->person));
        } catch (Refusal $refusal) {
            self::$refusals[] = $refusal;
        }
    }
}
