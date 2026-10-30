<?php

use AgenticActions\Facades\Actions;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Http\PublishPostOnGet;
use Tests\Fixtures\Http\ShowPost;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

it('refuses a #[Rest] GET on a Write with 405 before it runs, so a link on another site cannot publish a post', function () {
    config([
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'agentic-actions.discovery.paths' => [],
        'agentic-actions.discovery.classes' => [PublishPostOnGet::class],
    ]);
    $this->refreshActions();

    $post = Post::factory()->for($user = User::factory()->create())->create(['status' => 'draft']);
    $this->mountRoutes(fn () => Route::middleware(['web', 'auth'])->group(fn () => Actions::rest()));

    // The signed-in author, with nothing but the session cookie a cross-site GET carries: no CSRF token.
    $this->actingAs($user)->get("/posts/{$post->id}/publish")->assertMethodNotAllowed();

    expect($post->fresh()->status)->toBe('draft');
});

it('refuses a #[Rest] GET on a Read with 405 to a session, so a link on another site cannot run it', function () {
    config([
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'agentic-actions.discovery.paths' => [],
        'agentic-actions.discovery.classes' => [ShowPost::class],
    ]);
    $this->refreshActions();

    $post = Post::factory()->for($user = User::factory()->create())->create();
    $this->mountRoutes(fn () => Route::middleware(['web', 'auth'])->group(fn () => Actions::rest()));

    $this->actingAs($user)->get("/posts/{$post->id}")->assertMethodNotAllowed()->assertHeader('Allow', 'POST');
});
