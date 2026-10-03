<?php

namespace Tests\Fixtures\Datasets;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The workbench's teams, with a global scope that hides the team a test names.
 */
final class ScopedTeam extends Model
{
    /**
     * The name of the team the global scope hides.
     */
    public static ?string $unseen = null;

    protected $table = 'teams';

    /**
     * Hide the named team.
     */
    protected static function booted(): void
    {
        self::addGlobalScope('visible', fn (Builder $query): Builder => $query->where('name', '!=', (string) self::$unseen));
    }
}
