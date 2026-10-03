<?php

namespace AgenticActions\Testing;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Contracts\InterceptsActions;
use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Exposure\Entry;
use AgenticActions\Outcome;
use AgenticActions\Refusal;
use Closure;
use PHPUnit\Framework\Assert;

/**
 * Answers every action call while it is bound: it records the call and returns the listed response. The gates,
 * authorize() and handle() never run, and no event fires. Bound by Actions::fake().
 *
 * @api
 */
final class ActionsFake implements InterceptsActions
{
    /**
     * The calls the fake answered, in order.
     *
     * @var list<array{class: string, name: string, input: array<string, mixed>, context: ActionContext}>
     */
    private array $calls = [];

    /**
     * Create a fake.
     *
     * @param  array<class-string<Action>, array<string, mixed>|Refusal|Closure>  $responses  an output, a refusal, or a closure given the input and the context
     */
    public function __construct(private readonly array $responses = []) {}

    /**
     * Record the call and return the faked outcome: the listed output, the listed refusal, the closure's result, or an
     * empty success for an unlisted action.
     *
     * @internal Called by the pipeline's doors.
     *
     * @param  array<string, mixed>  $input
     */
    public function respond(Entry $entry, array $input, ActionContext $context): Outcome
    {
        $this->calls[] = ['class' => $entry->class, 'name' => $entry->name, 'input' => $input, 'context' => $context];

        $response = $this->responses[$entry->class] ?? null;

        if ($response instanceof Refusal) {
            return Outcome::refusedBy($entry, $context, $response);
        }

        if ($response instanceof Closure) {
            try {
                $result = $response($input, $context);
            } catch (Refusal $refusal) {
                return Outcome::refusedBy($entry, $context, $refusal);
            }

            return Outcome::completed($entry, $context, $result, is_array($result) ? $result : [], null);
        }

        return Outcome::completed($entry, $context, $response, $response ?? [], null);
    }

    /**
     * Fail unless the action ran, and, with a callback, unless one call's input and context satisfy it.
     *
     * @param  string  $action  a class or an action name
     * @param  (Closure(array<string, mixed>, ActionContext): bool)|null  $callback
     */
    public function assertRan(string $action, ?Closure $callback = null): void
    {
        Assert::assertNotEmpty(
            $this->matching($action, $callback),
            $callback === null
                ? "The expected action [{$action}] did not run."
                : "The expected action [{$action}] did not run with input and context the callback accepts.",
        );
    }

    /**
     * Fail when the action ran, or, with a callback, when one call's input and context satisfy it.
     *
     * @param  string  $action  a class or an action name
     * @param  (Closure(array<string, mixed>, ActionContext): bool)|null  $callback
     */
    public function assertNotRan(string $action, ?Closure $callback = null): void
    {
        Assert::assertEmpty(
            $this->matching($action, $callback),
            $callback === null
                ? "The unexpected action [{$action}] ran."
                : "The unexpected action [{$action}] ran with input and context the callback accepts.",
        );
    }

    /**
     * Fail unless the action ran exactly this many times.
     *
     * @param  string  $action  a class or an action name
     */
    public function assertRanTimes(string $action, int $times): void
    {
        $count = count($this->matching($action));

        Assert::assertSame($times, $count, "The expected action [{$action}] ran {$count} times instead of {$times} times.");
    }

    /**
     * Fail when any action ran.
     */
    public function assertNothingRan(): void
    {
        $names = array_map(fn (array $call): string => $call['name'], $this->calls);

        Assert::assertEmpty($names, 'Actions ran unexpectedly: '.implode(', ', $names).'.');
    }

    /**
     * The recorded calls to an action, narrowed by the callback when one is given. A name no action has fails the
     * test, so a misspelt name never passes assertNotRan().
     *
     * @param  (Closure(array<string, mixed>, ActionContext): bool)|null  $callback
     * @return list<array{class: string, name: string, input: array<string, mixed>, context: ActionContext}>
     */
    private function matching(string $action, ?Closure $callback = null): array
    {
        $class = ltrim($action, '\\');
        $isClass = class_exists($class);

        $calls = array_values(array_filter(
            $this->calls,
            fn (array $call): bool => $isClass ? $call['class'] === $class : $call['name'] === $action,
        ));

        if (! $isClass && $calls === [] && app(ActionRegistry::class)->find($action) === null) {
            Assert::fail("No action is named [{$action}]. Pass an action's class or the name actions:list shows.");
        }

        if ($callback === null) {
            return $calls;
        }

        return array_values(array_filter($calls, fn (array $call): bool => (bool) $callback($call['input'], $call['context'])));
    }
}
