<?php

use AgenticActions\Streaming\ActionsProtocol;
use AgenticActions\Streaming\ChatRequest;
use App\Ai\OneStepTwoToolsGateway;
use App\Ai\ProbeAssistant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
 * The stream probe's routes, required from routes/web.php. GET, so curl and a page's fetch() can call them with no
 * CSRF token; the stream itself is the chat recipe's line of docs/copilot.md.
 */

// One turn: the first user signs in, the run id becomes the chat request's one message, and the recipe streams it.
Route::get('/probe/stream', function (Request $request) {
    $run = (string) $request->query('run', 'probe');

    config(['ai.conversations.generate_title' => false]);

    Auth::login(User::query()->orderBy('id')->firstOrFail());

    (new OneStepTwoToolsGateway($run))->fake();

    $request->merge(['messages' => [
        ['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => "Save a note and look it up (run {$run})."]]],
    ]]);

    $chat = ChatRequest::from($request);

    return (new ProbeAssistant($request->user()))
        ->continueLastConversation($request->user())
        ->stream($chat)
        ->usingProtocol(new ActionsProtocol);
});

// What a run left behind: its tools' timings, and the stored user and assistant messages that name it.
Route::get('/probe/runs', function (Request $request) {
    $run = (string) $request->query('run');

    return [
        'tools' => DB::table('probe_runs')->where('run', $run)->orderBy('id')->get(['tool', 'started_at', 'finished_at', 'connection_status']),
        'messages' => DB::table('agent_conversation_messages')->where('content', 'like', "%(run {$run}).")->orderBy('id')->get(['role', 'status']),
    ];
});

// What serves the probe: the SAPI, PHP's version and output buffering, and the web server.
Route::get('/probe/info', fn (Request $request) => sprintf(
    '%s, PHP %s, output_buffering=%s, %s',
    PHP_SAPI,
    PHP_VERSION,
    ini_get('output_buffering'),
    $request->server('SERVER_SOFTWARE', 'unknown server'),
));

// A page whose own fetch() reader logs each part with performance.now(), for the headless Chrome check.
Route::get('/probe/page', fn (Request $request) => view('probe-page', ['run' => (string) $request->query('run', 'browser')]));
