<?php

use AgenticActions\Facades\Actions;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Workbench\App\Http\Controllers\ImportController;
use Workbench\App\Http\Controllers\TeamAssistantController;

/*
 * Testbench loads this file inside the "api" middleware group but without a prefix, so the prefix is added here and
 * the URL is /api/actions/{action}, as in an app after install:api. Tenant-scoped actions stay on teams/{team}.
 */

Route::middleware('auth:sanctum')->prefix('api')->name('api.')->group(fn () => Actions::routes(tenant: false));

// A hand-written token route that queues an action. It is on the MCP guard, so it names the abilities it accepts.
Route::middleware(['auth:sanctum', CheckForAnyAbility::class.':actions:read,actions:write'])
    ->post('api/teams/{team}/imports', ImportController::class)
    ->name('api.teams.imports');

// The team assistant for a token client. It chats as any client does, but its answers to a card never count: only the
// member's own session confirms a call, so a call it asks for waits until the member answers in the browser.
Route::middleware(['auth:sanctum', CheckForAnyAbility::class.':actions:read,actions:write'])
    ->post('api/teams/{team}/assistant', [TeamAssistantController::class, 'stream'])
    ->name('api.teams.assistant');
