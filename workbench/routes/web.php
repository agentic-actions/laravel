<?php

use AgenticActions\Facades\Actions;
use AgenticActions\Streaming\ActionsProtocol;
use AgenticActions\Streaming\ChatRequest;
use AgenticActions\Streaming\Transcript;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Workbench\App\Ai\BlogAssistant;
use Workbench\App\Http\Controllers\PostFormController;
use Workbench\App\Http\Controllers\TeamAssistantController;

/*
 * Testbench loads this file inside the "web" middleware group.
 */

Route::get('login', fn () => 'Sign in to write posts.')->name('login');

Route::middleware('auth')->group(fn () => Actions::routes(tenant: false));

Route::middleware('auth')->prefix('teams/{team}')->name('teams.')->group(fn () => Actions::routes(tenant: true));

Route::middleware('auth')->get('posts/create', PostFormController::class)->name('posts.create');

// The chat endpoint of docs/copilot.md, as an app writes it.
Route::middleware(['auth', 'throttle:20,1'])->post('/assistant', function (Request $request) {
    $chat = ChatRequest::from($request);

    if ($chat->isEmpty()) {
        return response()->json([
            'message' => __('agentic-actions::stream.empty', ['max' => config('agentic-actions.agents.max_message_length')]),
        ], 422);
    }

    return (new BlogAssistant($request->user()))
        ->continueLastConversation($request->user())
        ->stream($chat)
        ->usingProtocol(new ActionsProtocol);
})->name('assistant');

// The reload of docs/data.md: the author's last conversation, with the tables its turns showed before their words.
Route::middleware(['auth', 'throttle:20,1'])->get('/assistant', function (Request $request) {
    $agent = (new BlogAssistant($request->user()))->continueLastConversation($request->user());
    $conversationId = $agent->currentConversation();

    return response()->json([
        'messages' => $conversationId === null ? [] : Transcript::forUseChat($conversationId, $request->user(), agent: $agent),
    ]);
})->name('assistant.transcript');

// The confirmation route of docs/copilot.md, for one member of one team, and the reload that restores a waiting card.
Route::middleware(['auth', 'throttle:20,1'])->prefix('teams/{team}')->name('teams.')->group(function () {
    Route::post('assistant', [TeamAssistantController::class, 'stream'])->name('assistant');
    Route::get('assistant', [TeamAssistantController::class, 'transcript'])->name('assistant.transcript');
});
