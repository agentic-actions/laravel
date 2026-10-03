<?php

namespace AgenticActions\Support;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * What actions:install and actions:check read about the tables a feature needs.
 *
 * @internal
 */
final class Migrations
{
    /**
     * Whether database/migrations holds a migration named "{date}_{name}.php", the check install:api makes for
     * Sanctum's. vendor:publish dates each copy anew, so the name alone tells.
     */
    public static function published(string $name): bool
    {
        return (glob(app()->databasePath('migrations').'/*_'.$name.'.php') ?: []) !== [];
    }

    /**
     * The tables the connection lacks, in the given order; none when the database cannot be read.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    public static function missingTables(?string $connection, array $tables): array
    {
        try {
            $schema = Schema::connection($connection);

            return array_values(array_filter($tables, fn (string $table): bool => ! $schema->hasTable($table)));
        } catch (Throwable) {
            return [];
        }
    }
}
