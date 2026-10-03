<?php

namespace Tests\Fixtures\Datasets;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workbench\App\Models\Post;

/**
 * A comment a team owns (team_id) on a post (post_id), which a team owns too. Its table is made by the test that uses
 * it.
 */
final class Comment extends Model
{
    protected $guarded = [];

    /**
     * The post commented on.
     *
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
