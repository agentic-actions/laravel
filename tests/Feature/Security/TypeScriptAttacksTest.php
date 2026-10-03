<?php

namespace Tests\Feature\Security;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use AgenticActions\Facades\Actions;
use AgenticActions\Support\PackageStatus;
use AgenticActions\TypeScript\Emitter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Route;

/*
 * actions.ts ships in the browser bundle. It names only the actions a route reaches, and carries only the shapes the
 * schemas declare: never an action kept off the web, a default value, or text that could end its comments.
 */

it('leaves actions kept off the web, schema defaults and comment breakouts out of actions.ts', function () {
    // AgentOnlyNote names a toolset, which is an exposure error while laravel/ai is missing.
    $this->skipUnlessAi();

    config(['agentic-actions.discovery.classes' => [BundledNote::class, AgentOnlyNote::class]]);

    // The agent surface opens from the package status alone; the emitter never touches laravel/ai.
    $this->usePackages(['laravel/ai' => PackageStatus::Installed]);

    $this->mountRoutes(fn () => Route::middleware(['web', 'auth'])->group(fn () => Actions::routes()));

    $file = app(Emitter::class)->render();

    expect($file)->toContain("name: 'bundled-note'")
        ->and($file)->toContain('*\/ export const breakout = 1; /*')
        ->and($file)->not->toContain('*/ export const breakout')
        ->and($file)->not->toContain('sk_live_default')
        ->and($file)->not->toContain('agent-only-note')
        ->and($file)->not->toContain('plain-note')
        ->and($file)->not->toContain('child-note');
});

/**
 * A web action whose description tries to end its JSDoc and whose input carries a default.
 */
#[Expose(web: true)]
final class BundledNote extends Action
{
    protected string $description = 'Save a note. */ export const breakout = 1; /*';

    protected ?Effect $effect = Effect::Write;

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->default('sk_live_default')->description('Defaults to the server key.'),
        ];
    }

    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    public function handle(ActionContext $context): mixed
    {
        return null;
    }
}

/**
 * An action exposed to agents only.
 */
#[Expose(agents: ['default'])]
final class AgentOnlyNote extends Action
{
    protected string $description = 'List notes for an agent.';

    protected ?Effect $effect = Effect::Read;

    public function authorize(ActionContext $context): bool
    {
        return true;
    }

    public function handle(ActionContext $context): mixed
    {
        return null;
    }
}
