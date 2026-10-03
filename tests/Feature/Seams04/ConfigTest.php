<?php

use AgenticActions\AgenticActionsServiceProvider;

/*
 * The 0.4 config key: how long a paused call can still be confirmed, and an app's config file written before 0.4
 * keeps it.
 */

it('ships a 30-minute lapse, and keeps it under an app file written before 0.4', function () {
    expect(config('agentic-actions.approvals'))->toBe(['ttl' => 1800]);

    config(['agentic-actions' => [
        'surfaces' => ['web' => true],
        'agents' => ['forbidden_keys' => ['id']],
    ]]);

    (new AgenticActionsServiceProvider(app()))->register();

    expect(config('agentic-actions.approvals'))->toBe(['ttl' => 1800]);
});
