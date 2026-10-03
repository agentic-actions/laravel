<?php

namespace AgenticActions\Exposure;

use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use AgenticActions\Support\PackageStatus;
use AgenticActions\Surface;

/**
 * The one exposure rule. Every adapter, actions:list, actions:check, the manifest and the snapshot read its result.
 *
 * @internal
 */
final class ExposureRule
{
    /**
     * Decide which surfaces an action opens, which it skips (surface value => reason), and which errors it carries
     * ("surface: reason" for a surface named on purpose that the rules refuse). Console is always open.
     *
     * @return array{0: list<Surface>, 1: array<string, string>, 2: list<string>}
     */
    public static function decide(?Expose $expose, ?Effect $effect, string $description, bool $hasAgentSchema, PackageStatus $ai): array
    {
        $surfaces = [Surface::Console];

        if ($expose === null) {
            return [$surfaces, ['http' => 'no #[Expose]', 'agent' => 'no #[Expose]', 'mcp' => 'no #[Expose]'], []];
        }

        $bare = $expose->isBare();

        $wanted = [
            'http' => $bare || $expose->web === true,
            'agent' => $bare || ($expose->agents ?? []) !== [],
            'mcp' => $bare || $expose->mcp === true,
        ];

        if (! in_array(true, $wanted, true)) {
            return [$surfaces, [], ['#[Expose] names no surface']];
        }

        $skipped = [];
        $errors = [];

        foreach ($wanted as $surface => $isWanted) {
            $model = $surface !== 'http';

            // "rule" skips quietly under a bare #[Expose] and is an error when the surface was named; "mistake" is
            // always an error; "pending" always skips. MCP follows the agent rules without the laravel/ai rows, and a
            // missing description only skips it under a bare #[Expose], as 0.1 allowed before MCP opened. Destructive
            // and External actions never open MCP, and open agents only when #[Expose(agents: [...])] names a toolset:
            // each of their agent calls then waits for a person to confirm it.
            [$reason, $kind] = match (true) {
                ! $isWanted => ['not declared', 'pending'],
                $effect === null => ['effect undeclared', 'mistake'],
                ! $model && $hasAgentSchema => ['agentSchema(): schema() then holds what fromAgent() builds, such as ids an agent never saw, not what a form sends; write this route by hand, or, when agents only need more fields than the web, drop agentSchema() and name them in requiredForAgents()', 'rule'],
                $surface === 'mcp' && ! $effect->isModelSafe() => ["effect {$effect->value}: MCP has no confirmation step", 'rule'],
                $surface === 'agent' && ! $effect->isModelSafe() && $bare => ["effect {$effect->value}: offered to agents only when #[Expose(agents: [...])] names a toolset", 'rule'],
                $surface === 'agent' && $ai === PackageStatus::Missing => ['laravel/ai is not installed', 'rule'],
                $surface === 'agent' && $ai === PackageStatus::DevOnly => ['laravel/ai is installed only as a dev requirement: move it to "require"', 'mistake'],
                $model && $description === '' => ['no description', $surface === 'mcp' ? 'rule' : 'mistake'],
                default => [null, null],
            };

            if ($reason === null) {
                $surfaces[] = Surface::from($surface);
            } elseif ($kind === 'mistake' || ($kind === 'rule' && ! $bare)) {
                $errors[] = "{$surface}: {$reason}";
            } else {
                $skipped[$surface] = $reason;
            }
        }

        return [$surfaces, $skipped, $errors];
    }
}
