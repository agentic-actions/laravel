<?php

namespace AgenticActions\Feed;

use AgenticActions\ActionContext;
use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Effect;
use AgenticActions\Events\ActionCompleted;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Exposure\Entry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The change feed: keeps the last time each touch key was written, per tenant (per actor for a write without one), in
 * the default cache store, and answers an open page's poll with the keys written since its cursor. Keys only, never
 * ids or values.
 *
 * @internal
 */
final class ChangeFeed
{
    /**
     * The cache key prefix; a new entry shape changes it.
     */
    public const PREFIX = 'agentic-actions:feed:v1:';

    /**
     * How far a handed-back cursor trails the clock, so a write recorded by a server whose clock lags is still read.
     */
    private const SKEW_MS = 2000;

    /**
     * Record a completed write's touches. Never throws: the write has committed, and its caller must not see this fail.
     */
    public function handle(ActionCompleted $event): void
    {
        if (! config('agentic-actions.feed.enabled') || $event->effect === null || $event->effect === Effect::Read) {
            return;
        }

        // A tenant's writes go to the tenant, so every member's open page hears of them; the rest go to the actor.
        $bucket = match (true) {
            $event->tenantId !== null => 'tenant:'.$event->tenantId,
            $event->actorId !== null => "actor:{$event->actorType}:{$event->actorId}",
            default => null,
        };

        if ($bucket === null) {
            return;
        }

        try {
            $touches = ClassExposure::of($event->class)->touches;
            $now = self::now();

            // One entry per bucket and key holding the key's last write time: a plain put, so no lock and no
            // read-modify-write. Two writers race only to store nearly the same time.
            Cache::putMany(
                array_fill_keys(array_map(fn (string $key): string => self::PREFIX."{$bucket}:{$key}", $touches === [] ? ['*'] : $touches), $now),
                (int) config('agentic-actions.feed.window', 600),
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * What changed for this context since the cursor. A null cursor only starts one; a cursor older than the window
     * reloads everything once.
     *
     * @return array{now: int, touches: list<string>}
     */
    public function since(ActionContext $context, ?int $since): array
    {
        $now = self::now();
        $cursor = $now - self::SKEW_MS;

        if ($since === null || $since > $now) {
            return ['now' => $cursor, 'touches' => []];
        }

        if ($since < $now - (int) config('agentic-actions.feed.window', 600) * 1000) {
            return ['now' => $cursor, 'touches' => ['*']];
        }

        // Every key the app declares, plus "*": one Cache::many() for both buckets (one query on the database store).
        $names = [];

        foreach (self::buckets($context) as $bucket) {
            foreach (self::keys() as $key) {
                $names[self::PREFIX."{$bucket}:{$key}"] = $key;
            }
        }

        $touches = [];

        // Some stores hand an integer back as a numeric string (redis), so any numeric value counts.
        foreach (Cache::many(array_keys($names)) as $name => $at) {
            if (is_numeric($at) && (int) $at >= $since) {
                $touches[] = $names[$name];
            }
        }

        $touches = array_values(array_unique($touches));

        return ['now' => $cursor, 'touches' => in_array('*', $touches, true) ? ['*'] : $touches];
    }

    /**
     * The touch keys any discovered action declares, and "*".
     *
     * @return list<string>
     */
    private static function keys(): array
    {
        $keys = array_merge(['*'], ...array_map(fn (Entry $entry): array => $entry->touches, array_values(app(ActionRegistry::class)->all())));

        return array_values(array_unique($keys));
    }

    /**
     * The buckets a poll reads: its actor's, and its tenant's when the group carries one.
     *
     * @return list<string>
     */
    private static function buckets(ActionContext $context): array
    {
        $actor = $context->actor(Authenticatable::class);
        $type = $actor instanceof Model ? $actor->getMorphClass() : $actor::class;
        $buckets = ["actor:{$type}:{$actor->getAuthIdentifier()}"];

        return $context->tenant === null ? $buckets : [...$buckets, 'tenant:'.$context->tenant->getKey()];
    }

    /**
     * The server clock in milliseconds, through Laravel's clock so tests can travel.
     */
    private static function now(): int
    {
        return (int) now()->getTimestampMs();
    }
}
