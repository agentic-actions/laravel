<?php

use AgenticActions\Console\Checks;
use AgenticActions\Facades\Actions;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\Fixtures\Actions\ListNotes;
use Workbench\App\Models\User;

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

it('refuses a GET from a session on a reads: get mount with 405, even when actions:check was never run', function (array $middleware) {
    config([
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'agentic-actions.discovery.paths' => [],
        'agentic-actions.discovery.classes' => [ListNotes::class],
    ]);
    $this->refreshActions();
    $this->mountRoutes(fn () => Route::middleware($middleware)->group(fn () => Actions::routes(reads: 'get')));

    // The signed-in person, with nothing but the session cookie a link on another site sends.
    $this->actingAs(User::factory()->create())
        ->get('/actions/list-notes')
        ->assertMethodNotAllowed()
        ->assertHeader('Allow', 'POST');
})->with([
    'the web group' => [['web', 'auth']],
    'a session guard without the web group' => [['auth:web']],
    'HTTP basic auth' => [['auth.basic']],
]);
