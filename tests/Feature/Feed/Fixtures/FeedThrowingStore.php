<?php

namespace Tests\Feature\Feed\Fixtures;

use Illuminate\Cache\ArrayStore;
use RuntimeException;

/**
 * A cache store that is down for writes.
 */
final class FeedThrowingStore extends ArrayStore
{
    /**
     * Refuse every write of several items.
     *
     * @param  array<string, mixed>  $values
     * @param  int  $seconds
     */
    public function putMany(array $values, $seconds): bool
    {
        throw new RuntimeException('The cache store is down.');
    }
}
