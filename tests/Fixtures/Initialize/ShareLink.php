<?php

namespace Tests\Fixtures\Initialize;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The link a post is shared by, made the first time anyone reads it. The tests that use it create its table.
 *
 * @property int $post_id
 * @property string $token
 */
final class ShareLink extends Model
{
    /**
     * One link per post: the post is the key.
     *
     * @var string
     */
    protected $primaryKey = 'post_id';

    /**
     * The key is the post's.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * Every attribute is mass assignable.
     *
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * Create the table for one test, as a temporary table, which MySQL creates without committing the test's
     * transaction. The key on post_id keeps one link per post, so two first reads at once cannot add two; it is the
     * primary key because MySQL writes that one inside CREATE TABLE, where any other index is an ALTER TABLE that
     * commits the transaction.
     */
    public static function createTable(): void
    {
        Schema::create('share_links', function (Blueprint $table): void {
            $table->temporary();
            $table->unsignedBigInteger('post_id')->primary();
            $table->string('token');
            $table->timestamps();
        });
    }

    /**
     * Drop the table after the test.
     */
    public static function dropTable(): void
    {
        Schema::dropIfExists('share_links');
    }
}
