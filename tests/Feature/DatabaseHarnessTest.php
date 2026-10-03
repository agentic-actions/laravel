<?php

use Illuminate\Support\Facades\Schema;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * The suite's database setup. The two cases run one after the other, so on MySQL and Postgres, where tables outlive a
 * test, the second one starts from the database the first one migrated.
 */

it('migrates Laravel\'s, Sanctum\'s and the workbench\'s tables together, and leaves no migration to roll back', function () {
    expect(Schema::hasTable('users'))->toBeTrue()
        ->and(Schema::hasTable('sessions'))->toBeTrue()
        ->and(Schema::hasTable('cache'))->toBeTrue()
        ->and(Schema::hasTable('jobs'))->toBeTrue()
        ->and(Schema::hasTable('personal_access_tokens'))->toBeTrue()
        ->and(Schema::hasTable('teams'))->toBeTrue()
        ->and(Schema::hasTable('team_user'))->toBeTrue()
        ->and(Schema::hasTable('posts'))->toBeTrue()
        ->and(count($this->cachedTestMigratorProcessors))->toBe(0, 'A migration is queued to roll back after the test.');

    Post::factory()->for(User::factory())->create();

    expect(Post::query()->count())->toBe(1);
})->with(['the first test', 'a later test'])->group('database');
