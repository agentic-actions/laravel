<?php

namespace Tests\Fixtures\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use Illuminate\Support\Facades\Cache;

/**
 * A Read that caches on the database store: the store's tables are writable with no setting.
 */
final class ReadThatCaches extends Action
{
    protected ?Effect $effect = Effect::Read;

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Cache a value, then read it back.
     */
    public function handle(ActionContext $context): mixed
    {
        Cache::store('database')->put('read-that-caches', 'cached', 60);

        return Cache::store('database')->get('read-that-caches');
    }
}
