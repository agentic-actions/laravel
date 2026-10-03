<?php

use AgenticActions\ActionContext;
use AgenticActions\Facades\Actions;
use AgenticActions\Pipeline\OutcomeKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Datasets\Invoice;
use Tests\Fixtures\Datasets\InvoicesByDay;
use Workbench\App\Models\User;

/*
 * A time the model casts to a date is a day, not an instant: it is compared with the range's own days and never moved
 * by the zone's offset. Read as an instant, a zone west of storage lost the month's first day, gained the next month's,
 * and counted each day's rows on the day before.
 */

beforeEach(function () {
    config(['agentic-actions.discovery.paths' => [], 'agentic-actions.discovery.classes' => [InvoicesByDay::class]]);
    $this->refreshActions();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->user = User::factory()->create();

    // A temporary table, which MySQL creates without committing the test's transaction.
    Schema::create('invoices', function (Blueprint $table): void {
        $table->temporary();
        $table->id();
        $table->date('issued_on');
        $table->timestamps();
    });

    foreach (['2026-09-01', '2026-09-02', '2026-09-02', '2026-09-30', '2026-10-01'] as $day) {
        Invoice::query()->create(['issued_on' => $day]);
    }
});

afterEach(function () {
    Schema::dropIfExists('invoices');
    InvoicesByDay::$zone = '';
});

it('counts each date on its own day, in any zone', function (string $zone) {
    InvoicesByDay::$zone = $zone;

    $outcome = Actions::attempt(InvoicesByDay::class, ['measures' => ['invoices'], 'grain' => 'day', 'since' => '2026-09-01', 'until' => '2026-09-30'], ActionContext::http($this->user));

    expect($outcome->kind())->toBe(OutcomeKind::Ok, (string) $outcome->exception()?->getMessage())
        ->and(array_filter(array_column($outcome->output()['rows'], 'invoices', 'issued')))->toBe(['2026-09-01' => 1, '2026-09-02' => 2, '2026-09-30' => 1]);
})->with([
    'UTC' => ['UTC'],
    'east of it' => ['Asia/Riyadh'],
    'west of it' => ['America/New_York'],
])->group('database');

it('keeps the range to its own days without a grain', function () {
    InvoicesByDay::$zone = 'America/New_York';

    $outcome = Actions::attempt(InvoicesByDay::class, ['measures' => ['invoices'], 'since' => '2026-09-01', 'until' => '2026-09-30'], ActionContext::http($this->user));

    expect($outcome->output()['rows'])->toBe([['invoices' => 4]]);
})->group('database');
