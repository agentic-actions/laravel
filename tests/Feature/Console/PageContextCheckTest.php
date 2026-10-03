<?php

use AgenticActions\Console\Checks;
use AgenticActions\Console\Finding;
use AgenticActions\Support\PackageStatus;
use Tests\Fixtures\Ai\NotesAgent;
use Tests\Fixtures\PageContext\NoMiddlewareAgent;
use Tests\Fixtures\PageContext\NoTraitAgent;
use Tests\Fixtures\PageContext\WiredAgent;

beforeEach(function () {
    $this->skipUnlessAi();

    $this->actions = dirname(__DIR__, 2).'/Fixtures/Actions';
});

/**
 * The "Page context" row's findings for the shared actions plus these classes, as [level, message] pairs.
 *
 * @param  list<class-string>  $classes
 * @return list<array{0: string, 1: string}>
 */
function pageContextFindings(string $actions, array $classes): array
{
    config(['agentic-actions.discovery.paths' => [$actions], 'agentic-actions.discovery.classes' => $classes]);

    return array_values(array_map(
        fn (Finding $finding): array => [$finding->level, $finding->message],
        array_filter(app(Checks::class)->run(), fn (Finding $finding): bool => $finding->row === 'Page context'),
    ));
}

/**
 * The "needs Inertia" failure for an agent.
 *
 * @param  class-string  $agent
 */
function needsInertia(string $agent): string
{
    return "{$agent}: #[WithPageContext] needs Inertia, and inertiajs/inertia-laravel is not installed for production, so the page never reaches the agent. Remove the attribute, or composer require inertiajs/inertia-laravel.";
}

/**
 * The wiring failure for an agent.
 *
 * @param  class-string  $agent
 */
function needsWiring(string $agent): string
{
    return "{$agent}: #[WithPageContext] takes effect only on an agent that uses InteractsWithActions and implements Laravel\\Ai\\Contracts\\HasMiddleware, with middleware() returning [...\$this->actionMiddleware()].";
}

it('passes an agent with the trait and HasMiddleware while Inertia is installed', function () {
    expect(pageContextFindings($this->actions, [WiredAgent::class]))->toBe([]);
});

it('fails an agent carrying the attribute when Inertia is not installed for production', function (PackageStatus $inertia) {
    $this->usePackages(['inertiajs/inertia-laravel' => $inertia]);

    expect(pageContextFindings($this->actions, [WiredAgent::class]))->toBe([['fail', needsInertia(WiredAgent::class)]]);
})->with(['missing' => PackageStatus::Missing, 'dev-only' => PackageStatus::DevOnly]);

it('fails an agent without the wiring that makes the attribute take effect', function (string $agent) {
    expect(pageContextFindings($this->actions, [$agent]))->toBe([['fail', needsWiring($agent)]]);
})->with(['no HasMiddleware' => NoMiddlewareAgent::class, 'no InteractsWithActions' => NoTraitAgent::class]);

it('reports both failures for one agent, and each agent on its own line', function () {
    $this->usePackages(['inertiajs/inertia-laravel' => PackageStatus::Missing]);

    expect(pageContextFindings($this->actions, [NoTraitAgent::class, WiredAgent::class]))->toBe([
        ['fail', needsInertia(NoTraitAgent::class)],
        ['fail', needsWiring(NoTraitAgent::class)],
        ['fail', needsInertia(WiredAgent::class)],
    ]);
});

it('adds no finding for an agent without the attribute, whatever its shape and Inertia', function () {
    $this->usePackages(['inertiajs/inertia-laravel' => PackageStatus::Missing]);

    expect(pageContextFindings($this->actions, [NotesAgent::class]))->toBe([]);
});
