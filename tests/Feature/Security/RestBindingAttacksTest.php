<?php

use AgenticActions\Facades\Actions;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\Http\BoundPostIds;
use Workbench\App\Models\User;

it('preserves original REST path values and refuses query replacements through bindings', function (string $path, array $expected) {
    config(['agentic-actions.discovery.paths' => [], 'agentic-actions.discovery.classes' => [BoundPostIds::class]]);
    $this->refreshActions();
    $this->mountRoutes(fn () => Route::middleware(['api', 'auth:sanctum'])->group(fn () => Actions::rest()));
    Sanctum::actingAs(User::factory()->create(), ['actions:read']);

    $this->getJson($path)->assertOk()->assertExactJson($expected);
    $this->getJson($path.'?post_id=99')->assertUnprocessable()->assertJsonValidationErrors([
        'post_id' => trans('agentic-actions::http.path_parameter'),
    ]);
})->with([
    'identity' => ['/identity/11/22', ['post_id' => 11, 'other_id' => 22]],
    'swap' => ['/swapped/11/22', ['post_id' => 22, 'other_id' => 11]],
]);
