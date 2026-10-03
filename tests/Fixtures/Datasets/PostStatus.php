<?php

namespace Tests\Fixtures\Datasets;

/**
 * A post's status as the posts table stores it, with a label a row shows in its place.
 */
enum PostStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    /**
     * The status as a person reads it.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }
}
