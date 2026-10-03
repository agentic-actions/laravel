<?php

namespace AgenticActions\Feed;

use AgenticActions\Events\ActionCompleted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The change feed module: records the keys completed writes touch, for open pages to poll.
 *
 * @internal
 */
final class FeedServiceProvider extends ServiceProvider
{
    /**
     * Record every completed write's touches. The listener reads feed.enabled on each event.
     */
    public function boot(): void
    {
        Event::listen(ActionCompleted::class, ChangeFeed::class);
    }
}
