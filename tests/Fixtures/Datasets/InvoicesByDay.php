<?php

namespace Tests\Fixtures\Datasets;

use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Datasets\Dataset;
use AgenticActions\Datasets\Dimension;
use AgenticActions\Datasets\Measure;

/**
 * Invoices counted by the day they were issued, a DATE column, in the zone the test chooses.
 */
#[Expose]
final class InvoicesByDay extends Dataset
{
    /**
     * The zone the days are counted in, if a test chooses one.
     */
    public static string $zone = '';

    protected string $description = 'Invoices by the day they were issued.';

    protected string $model = Invoice::class;

    protected bool $tenantScoped = false;

    /**
     * Count the days in the zone the test chose, or in app.timezone.
     */
    public function __construct()
    {
        $this->timezone = self::$zone;
    }

    /**
     * The day an invoice was issued.
     */
    public function dimensions(): array
    {
        return [Dimension::time('issued', 'Issued', 'issued_on')];
    }

    /**
     * How many invoices.
     */
    public function measures(): array
    {
        return [Measure::count('invoices', 'Invoices')];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }
}
