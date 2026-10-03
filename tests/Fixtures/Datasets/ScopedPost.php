<?php

namespace Tests\Fixtures\Datasets;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The workbench's posts, with the tenant as the model's own global scope: only the posts of the team a test names. Its
 * team is a ScopedTeam, which has a global scope of its own.
 */
final class ScopedPost extends Model
{
    /**
     * The team whose posts the global scope keeps.
     */
    public static int|string|null $team = null;

    protected $table = 'posts';

    /**
     * The post's team, through its own model and global scope.
     *
     * @return BelongsTo<ScopedTeam, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(ScopedTeam::class, 'team_id');
    }

    /**
     * Keep the team's posts only.
     */
    protected static function booted(): void
    {
        self::addGlobalScope('team', fn (Builder $query): Builder => $query->where('team_id', self::$team));
    }
}
