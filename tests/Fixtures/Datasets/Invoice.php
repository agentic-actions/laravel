<?php

namespace Tests\Fixtures\Datasets;

use Illuminate\Database\Eloquent\Model;

/**
 * An invoice with a DATE column: a day, not an instant, as invoice, hire and booking dates often are. Its table is made
 * by the test that uses it.
 */
final class Invoice extends Model
{
    protected $guarded = [];

    /**
     * The day it was issued, as a date.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['issued_on' => 'date'];
    }
}
