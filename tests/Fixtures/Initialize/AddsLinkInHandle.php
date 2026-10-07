<?php

namespace Tests\Fixtures\Initialize;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;

/**
 * A Read that names no table in $initializes and adds a share link from handle(): the Read guard refuses it wherever
 * it runs, inside another Read's initialize() too.
 */
final class AddsLinkInHandle extends Action
{
    protected ?Effect $effect = Effect::Read;

    /**
     * Anyone.
     */
    public function authorize(ActionContext $context): bool
    {
        return true;
    }

    /**
     * Try to add a link.
     */
    public function handle(): ShareLink
    {
        return ShareLink::query()->create(['post_id' => 999, 'token' => 'from-a-nested-read']);
    }
}
