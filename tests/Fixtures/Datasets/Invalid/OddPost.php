<?php

namespace Tests\Fixtures\Datasets\Invalid;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Workbench\App\Models\Team;

/**
 * The workbench's posts, with relations a dataset cannot read through: a morph, a BelongsTo to a column that is not
 * the team's primary key, and one with a condition of its own.
 */
final class OddPost extends Model
{
    protected $table = 'posts';

    /**
     * Whatever the post is about.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The team, by its slug.
     *
     * @return BelongsTo<Team, $this>
     */
    public function teamBySlug(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id', 'slug');
    }

    /**
     * The team, when it has a name.
     *
     * @return BelongsTo<Team, $this>
     */
    public function namedTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id')->where('name', '!=', '');
    }
}
