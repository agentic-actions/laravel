<?php

use AgenticActions\Console\Checks;
use AgenticActions\Console\Finding;
use Tests\Fixtures\Approvals\ConfirmedDelete;
use Tests\Fixtures\Checks\AskDraft;

/**
 * The Cache store row's findings in this environment, with the default store on this driver.
 *
 * @return list<array{0: string, 1: string, 2: string}>
 */
function cacheStoreFindings(string $environment, string $driver): array
{
    app()['env'] = $environment;

    config(['cache.default' => 'shared', 'cache.stores.shared' => ['driver' => $driver]]);

    return array_values(array_map(
        fn (Finding $finding): array => [$finding->level, $finding->row, $finding->message],
        array_filter(app(Checks::class)->run(), fn (Finding $finding): bool => $finding->row === 'Cache store'),
    ));
}

it('warns outside local development about a store that forgets, keeps per visitor, or keeps on one server', function (string $driver, string $message) {
    expect(cacheStoreFindings('production', $driver))->toBe([['warn', 'Cache store', $message]]);
})->with([
    'array' => ['array', 'The change feed keeps touches in the [shared] cache store, which forgets them after each request, so open pages never hear of writes made elsewhere. Use a shared store such as database or redis.'],
    'null' => ['null', 'The change feed keeps touches in the [shared] cache store, which forgets them after each request, so open pages never hear of writes made elsewhere. Use a shared store such as database or redis.'],
    'session' => ['session', 'The change feed keeps touches in the [shared] cache store, which keeps them per visitor, so other people\'s pages never hear of writes. Use a shared store such as database or redis.'],
    'file' => ['file', 'The change feed keeps touches in the [shared] cache store (file), which servers do not share. With more than one server, use a shared store such as database or redis.'],
    'apc' => ['apc', 'The change feed keeps touches in the [shared] cache store (apc), which servers do not share. With more than one server, use a shared store such as database or redis.'],
    'octane' => ['octane', 'The change feed keeps touches in the [shared] cache store (octane), which servers do not share. With more than one server, use a shared store such as database or redis.'],
]);

// With laravel/ai, the action a person confirms brings the confirmations half in too; both pass or stay silent here.
it('passes a store every server shares', function (string $driver) {
    config(['agentic-actions.discovery.classes' => [ConfirmedDelete::class]]);

    expect(cacheStoreFindings('production', $driver))->toBe([]);
})->with(['redis', 'database', 'memcached', 'dynamodb']);

it('reports nothing in local development or in tests', function (string $environment) {
    config(['agentic-actions.discovery.classes' => [ConfirmedDelete::class]]);

    expect(cacheStoreFindings($environment, 'array'))->toBe([]);
})->with(['local', 'testing']);

it('reports nothing with the feed off', function () {
    config(['agentic-actions.feed.enabled' => false]);

    expect(cacheStoreFindings('production', 'array'))->toBe([]);
});

describe('confirmations', function () {
    beforeEach(function () {
        $this->skipUnlessAi();

        config(['agentic-actions.discovery.classes' => [ConfirmedDelete::class]]);
    });

    it('warns outside local development about any store but database, redis, memcached and dynamodb', function (string $driver, string $message) {
        config(['agentic-actions.feed.enabled' => false]);

        expect(cacheStoreFindings('production', $driver))->toBe([['warn', 'Cache store', $message]]);
    })->with([
        'array' => ['array', 'Confirmations and forms are kept in the [shared] cache store, which forgets them after each request, so every answer is refused. Use a database, redis, memcached or dynamodb store.'],
        'null' => ['null', 'Confirmations and forms are kept in the [shared] cache store, which forgets them after each request, so every answer is refused. Use a database, redis, memcached or dynamodb store.'],
        'session' => ['session', 'Confirmations and forms are kept in the [shared] cache store (session), which cannot hold a lock, so every answer is refused. Use a database, redis, memcached or dynamodb store.'],
        'apc' => ['apc', 'Confirmations and forms are kept in the [shared] cache store (apc), which cannot hold a lock, so every answer is refused. Use a database, redis, memcached or dynamodb store.'],
        'storage' => ['storage', 'Confirmations and forms are kept in the [shared] cache store (storage), which cannot hold a lock, so every answer is refused. Use a database, redis, memcached or dynamodb store.'],
        'file' => ['file', 'Confirmations and forms are kept in the [shared] cache store (file). An answer is single-use only on a database, redis, memcached or dynamodb store every server shares. Use one of those.'],
        'failover' => ['failover', 'Confirmations and forms are kept in the [shared] cache store (failover). An answer is single-use only on a database, redis, memcached or dynamodb store every server shares. Use one of those.'],
        'a custom driver' => ['mongodb', 'Confirmations and forms are kept in the [shared] cache store (mongodb). An answer is single-use only on a database, redis, memcached or dynamodb store every server shares. Use one of those.'],
    ]);

    it('keeps the feed sentence first when both halves warn', function () {
        expect(array_column(cacheStoreFindings('production', 'file'), 2))->toBe([
            'The change feed keeps touches in the [shared] cache store (file), which servers do not share. With more than one server, use a shared store such as database or redis.',
            'Confirmations and forms are kept in the [shared] cache store (file). An answer is single-use only on a database, redis, memcached or dynamodb store every server shares. Use one of those.',
        ]);
    });

    it('warns for an asking action on the agent surface as for a confirmed one', function () {
        config(['agentic-actions.discovery.classes' => [AskDraft::class]]);

        expect(array_column(cacheStoreFindings('production', 'file'), 2))->toContain(
            'Confirmations and forms are kept in the [shared] cache store (file). An answer is single-use only on a database, redis, memcached or dynamodb store every server shares. Use one of those.',
        );
    });

    it('says nothing of confirmations when no action a person confirms or answers is on the agent surface', function () {
        config(['agentic-actions.discovery.classes' => []]);

        expect(array_column(cacheStoreFindings('production', 'array'), 2))->toBe([
            'The change feed keeps touches in the [shared] cache store, which forgets them after each request, so open pages never hear of writes made elsewhere. Use a shared store such as database or redis.',
        ]);
    });
});
