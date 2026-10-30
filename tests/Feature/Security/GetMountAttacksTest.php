<?php

use AgenticActions\Console\Checks;
use AgenticActions\Facades\Actions;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\Fixtures\Actions\ListNotes;

it('rejects a session-capable Sanctum GET mount in the route checks', function () {
    config(['agentic-actions.discovery.paths' => [], 'agentic-actions.discovery.classes' => [ListNotes::class]]);
    $this->refreshActions();
    $this->mountRoutes(fn () => Route::middleware([
        EnsureFrontendRequestsAreStateful::class,
        'auth:sanctum',
    ])->group(fn () => Actions::routes(reads: 'get')));

    $findings = array_values(array_filter(app(Checks::class)->run(),
        fn ($finding): bool => $finding->row === 'Routes' && $finding->level === 'fail'));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('its group can start a Sanctum session');
});
