<?php

namespace Tests\Fixtures\Security;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use RuntimeException;

/**
 * A Read whose input-free authorize() throws with a record value in its message once a test asks it to, so the tool
 * list is built while it crashes.
 */
#[Expose]
final class HostileListing extends Action
{
    /**
     * Whether authorize() throws.
     */
    public static bool $crash = false;

    protected string $description = 'List the signed-in person\'s secrets.';

    protected ?Effect $effect = Effect::Read;

    protected bool $tenantScoped = false;

    /**
     * Crash with a record value when asked.
     */
    public function authorize(ActionContext $context): bool
    {
        if (self::$crash) {
            throw new RuntimeException('Customer "Secret Listing Customer" is locked.');
        }

        return $context->actor !== null;
    }

    /**
     * Nothing to read.
     */
    public function handle(ActionContext $context): string
    {
        return 'none';
    }
}
