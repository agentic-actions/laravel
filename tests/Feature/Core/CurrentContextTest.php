<?php

use AgenticActions\ActionContext;
use AgenticActions\Surface;
use Tests\Fixtures\Context\RememberContext;
use Workbench\App\Models\User;

/*
 * ActionContext::current(): the context of the action running now, for code an action calls that is not handed it
 * (a model event, a service): the innermost run, the one around it again after a nested run, null outside any run.
 */

beforeEach(function () {
    RememberContext::$seen = [];
});

it('is null outside any run', function () {
    expect(ActionContext::current())->toBeNull();
});

it('is the running action\'s context, with its surface and actor', function () {
    $context = ActionContext::agent(User::factory()->create());

    RememberContext::run([], $context);

    expect(RememberContext::$seen[0]?->surface)->toBe(Surface::Agent)
        ->and(RememberContext::$seen[0]?->actor?->getAuthIdentifier())->toBe($context->actor?->getAuthIdentifier())
        ->and(ActionContext::current())->toBeNull();
});

it('is the innermost run inside a nested one, and the outer one again after it', function () {
    RememberContext::run(['nested' => true], ActionContext::http(User::factory()->create()));

    expect(array_map(fn (?ActionContext $context): ?Surface => $context?->surface, RememberContext::$seen))
        ->toBe([Surface::Http, Surface::System, Surface::Http]);
});

it('is null again after a run that threw', function () {
    try {
        RememberContext::run(['throws' => true], ActionContext::http(null));
    } catch (Throwable) {
        //
    }

    expect(RememberContext::$seen)->toHaveCount(1)
        ->and(ActionContext::current())->toBeNull();
});
