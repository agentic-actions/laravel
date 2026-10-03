<?php

use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use AgenticActions\Exposure\ExposureRule;
use AgenticActions\Support\PackageStatus;
use AgenticActions\Surface;

/**
 * Decide with the common defaults: a description, no agentSchema(), laravel/ai installed.
 *
 * @return array{0: list<Surface>, 1: array<string, string>, 2: list<string>}
 */
function exposureDecision(?Expose $expose, ?Effect $effect, string $description = 'Does a thing.', bool $agentSchema = false, PackageStatus $ai = PackageStatus::Installed): array
{
    return ExposureRule::decide($expose, $effect, $description, $agentSchema, $ai);
}

it('opens only the console without #[Expose]', function () {
    [$surfaces, $skipped, $errors] = exposureDecision(null, Effect::Write);

    expect($surfaces)->toBe([Surface::Console])
        ->and($skipped)->toBe(['http' => 'no #[Expose]', 'agent' => 'no #[Expose]', 'mcp' => 'no #[Expose]'])
        ->and($errors)->toBe([]);
});

it('follows every row of the exposure table', function (?Expose $expose, ?Effect $effect, bool $agentSchema, PackageStatus $ai, array $surfaces, array $skipped, array $errors) {
    expect(exposureDecision($expose, $effect, 'Does a thing.', $agentSchema, $ai))->toBe([$surfaces, $skipped, $errors]);
})->with([
    'null effect, bare' => [new Expose, null, false, PackageStatus::Installed, [Surface::Console], [], ['http: effect undeclared', 'agent: effect undeclared', 'mcp: effect undeclared']],
    'null effect, web named' => [new Expose(web: true), null, false, PackageStatus::Installed, [Surface::Console], ['agent' => 'not declared', 'mcp' => 'not declared'], ['http: effect undeclared']],
    'null effect, agents named' => [new Expose(agents: ['x']), null, false, PackageStatus::Installed, [Surface::Console], ['http' => 'not declared', 'mcp' => 'not declared'], ['agent: effect undeclared']],
    'read, bare, laravel/ai installed' => [new Expose, Effect::Read, false, PackageStatus::Installed, [Surface::Console, Surface::Http, Surface::Agent, Surface::Mcp], [], []],
    'write, bare, laravel/ai installed' => [new Expose, Effect::Write, false, PackageStatus::Installed, [Surface::Console, Surface::Http, Surface::Agent, Surface::Mcp], [], []],
    'write, bare, agentSchema()' => [new Expose, Effect::Write, true, PackageStatus::Installed, [Surface::Console, Surface::Agent, Surface::Mcp], ['http' => 'agentSchema(): schema() then holds what fromAgent() builds, such as ids an agent never saw, not what a form sends; write this route by hand, or, when agents only need more fields than the web, drop agentSchema() and name them in requiredForAgents()'], []],
    'write, web named, agentSchema()' => [new Expose(web: true), Effect::Write, true, PackageStatus::Installed, [Surface::Console], ['agent' => 'not declared', 'mcp' => 'not declared'], ['http: agentSchema(): schema() then holds what fromAgent() builds, such as ids an agent never saw, not what a form sends; write this route by hand, or, when agents only need more fields than the web, drop agentSchema() and name them in requiredForAgents()']],
    'write, web named' => [new Expose(web: true), Effect::Write, false, PackageStatus::Installed, [Surface::Console, Surface::Http], ['agent' => 'not declared', 'mcp' => 'not declared'], []],
    'write, web named, laravel/ai missing' => [new Expose(web: true), Effect::Write, false, PackageStatus::Missing, [Surface::Console, Surface::Http], ['agent' => 'not declared', 'mcp' => 'not declared'], []],
    'write, web named, laravel/ai dev-only' => [new Expose(web: true), Effect::Write, false, PackageStatus::DevOnly, [Surface::Console, Surface::Http], ['agent' => 'not declared', 'mcp' => 'not declared'], []],
    'write, agents named' => [new Expose(agents: ['support']), Effect::Write, false, PackageStatus::Installed, [Surface::Console, Surface::Agent], ['http' => 'not declared', 'mcp' => 'not declared'], []],
    'write, both named' => [new Expose(web: true, agents: ['default']), Effect::Write, false, PackageStatus::Installed, [Surface::Console, Surface::Http, Surface::Agent], ['mcp' => 'not declared'], []],
    'write, bare, laravel/ai missing' => [new Expose, Effect::Write, false, PackageStatus::Missing, [Surface::Console, Surface::Http, Surface::Mcp], ['agent' => 'laravel/ai is not installed'], []],
    'write, agents named, laravel/ai missing' => [new Expose(agents: ['x']), Effect::Write, false, PackageStatus::Missing, [Surface::Console], ['http' => 'not declared', 'mcp' => 'not declared'], ['agent: laravel/ai is not installed']],
    'write, bare, laravel/ai dev-only' => [new Expose, Effect::Write, false, PackageStatus::DevOnly, [Surface::Console, Surface::Http, Surface::Mcp], [], ['agent: laravel/ai is installed only as a dev requirement: move it to "require"']],
    'write, agents named, laravel/ai dev-only' => [new Expose(agents: ['x']), Effect::Write, false, PackageStatus::DevOnly, [Surface::Console], ['http' => 'not declared', 'mcp' => 'not declared'], ['agent: laravel/ai is installed only as a dev requirement: move it to "require"']],
    'destructive, bare' => [new Expose, Effect::Destructive, false, PackageStatus::Installed, [Surface::Console, Surface::Http], ['agent' => 'effect destructive: offered to agents only when #[Expose(agents: [...])] names a toolset', 'mcp' => 'effect destructive: MCP has no confirmation step'], []],
    'external, bare' => [new Expose, Effect::External, false, PackageStatus::Installed, [Surface::Console, Surface::Http], ['agent' => 'effect external: offered to agents only when #[Expose(agents: [...])] names a toolset', 'mcp' => 'effect external: MCP has no confirmation step'], []],
    'destructive, bare, agentSchema()' => [new Expose, Effect::Destructive, true, PackageStatus::Installed, [Surface::Console], ['http' => 'agentSchema(): schema() then holds what fromAgent() builds, such as ids an agent never saw, not what a form sends; write this route by hand, or, when agents only need more fields than the web, drop agentSchema() and name them in requiredForAgents()', 'agent' => 'effect destructive: offered to agents only when #[Expose(agents: [...])] names a toolset', 'mcp' => 'effect destructive: MCP has no confirmation step'], []],
    'destructive, web named' => [new Expose(web: true), Effect::Destructive, false, PackageStatus::Installed, [Surface::Console, Surface::Http], ['agent' => 'not declared', 'mcp' => 'not declared'], []],
    'destructive, agents named' => [new Expose(agents: ['x']), Effect::Destructive, false, PackageStatus::Installed, [Surface::Console, Surface::Agent], ['http' => 'not declared', 'mcp' => 'not declared'], []],
    'destructive, web and agents named' => [new Expose(web: true, agents: ['team']), Effect::Destructive, false, PackageStatus::Installed, [Surface::Console, Surface::Http, Surface::Agent], ['mcp' => 'not declared'], []],
    'destructive, agents named, agentSchema()' => [new Expose(agents: ['x']), Effect::Destructive, true, PackageStatus::Installed, [Surface::Console, Surface::Agent], ['http' => 'not declared', 'mcp' => 'not declared'], []],
    'destructive, agents named, laravel/ai missing' => [new Expose(agents: ['x']), Effect::Destructive, false, PackageStatus::Missing, [Surface::Console], ['http' => 'not declared', 'mcp' => 'not declared'], ['agent: laravel/ai is not installed']],
    'destructive, agents named, laravel/ai dev-only' => [new Expose(agents: ['x']), Effect::Destructive, false, PackageStatus::DevOnly, [Surface::Console], ['http' => 'not declared', 'mcp' => 'not declared'], ['agent: laravel/ai is installed only as a dev requirement: move it to "require"']],
    'external, agents named' => [new Expose(agents: ['x']), Effect::External, false, PackageStatus::Installed, [Surface::Console, Surface::Agent], ['http' => 'not declared', 'mcp' => 'not declared'], []],
    'null effect, mcp named' => [new Expose(mcp: true), null, false, PackageStatus::Installed, [Surface::Console], ['http' => 'not declared', 'agent' => 'not declared'], ['mcp: effect undeclared']],
    'read, mcp named' => [new Expose(mcp: true), Effect::Read, false, PackageStatus::Installed, [Surface::Console, Surface::Mcp], ['http' => 'not declared', 'agent' => 'not declared'], []],
    'write, mcp named, agentSchema()' => [new Expose(mcp: true), Effect::Write, true, PackageStatus::Installed, [Surface::Console, Surface::Mcp], ['http' => 'not declared', 'agent' => 'not declared'], []],
    'write, mcp named, laravel/ai missing' => [new Expose(mcp: true), Effect::Write, false, PackageStatus::Missing, [Surface::Console, Surface::Mcp], ['http' => 'not declared', 'agent' => 'not declared'], []],
    'write, mcp named, laravel/ai dev-only' => [new Expose(mcp: true), Effect::Write, false, PackageStatus::DevOnly, [Surface::Console, Surface::Mcp], ['http' => 'not declared', 'agent' => 'not declared'], []],
    'destructive, mcp named' => [new Expose(mcp: true), Effect::Destructive, false, PackageStatus::Installed, [Surface::Console], ['http' => 'not declared', 'agent' => 'not declared'], ['mcp: effect destructive: MCP has no confirmation step']],
    'destructive, agents and mcp named' => [new Expose(agents: ['x'], mcp: true), Effect::Destructive, false, PackageStatus::Installed, [Surface::Console, Surface::Agent], ['http' => 'not declared'], ['mcp: effect destructive: MCP has no confirmation step']],
    'external, mcp named' => [new Expose(mcp: true), Effect::External, false, PackageStatus::Installed, [Surface::Console], ['http' => 'not declared', 'agent' => 'not declared'], ['mcp: effect external: MCP has no confirmation step']],
    'write, every surface named' => [new Expose(web: true, agents: ['default'], mcp: true), Effect::Write, false, PackageStatus::Installed, [Surface::Console, Surface::Http, Surface::Agent, Surface::Mcp], [], []],
    'web: false names nothing' => [new Expose(web: false), Effect::Write, false, PackageStatus::Installed, [Surface::Console], [], ['#[Expose] names no surface']],
    'agents: [] names nothing' => [new Expose(agents: []), Effect::Write, false, PackageStatus::Installed, [Surface::Console], [], ['#[Expose] names no surface']],
]);

it('requires a description on model surfaces, skipping MCP quietly under a bare #[Expose]', function () {
    expect(exposureDecision(new Expose, Effect::Write, ''))->toBe([
        [Surface::Console, Surface::Http],
        ['mcp' => 'no description'],
        ['agent: no description'],
    ])->and(exposureDecision(new Expose(agents: ['x']), Effect::Read, ''))->toBe([
        [Surface::Console],
        ['http' => 'not declared', 'mcp' => 'not declared'],
        ['agent: no description'],
    ])->and(exposureDecision(new Expose(mcp: true), Effect::Read, ''))->toBe([
        [Surface::Console],
        ['http' => 'not declared', 'agent' => 'not declared'],
        ['mcp: no description'],
    ])->and(exposureDecision(new Expose(agents: ['x']), Effect::Destructive, ''))->toBe([
        [Surface::Console],
        ['http' => 'not declared', 'mcp' => 'not declared'],
        ['agent: no description'],
    ])->and(exposureDecision(new Expose(web: true), Effect::Write, ''))->toBe([
        [Surface::Console, Surface::Http],
        ['agent' => 'not declared', 'mcp' => 'not declared'],
        [],
    ]);
});
