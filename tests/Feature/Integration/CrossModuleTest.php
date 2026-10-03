<?php

use AgenticActions\Contracts\InterceptsActions;
use AgenticActions\Facades\Actions;
use AgenticActions\Refusal;
use AgenticActions\Testing\ActionAssertions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\ListNotes;
use Tests\Fixtures\Actions\Trace;
use Tests\Fixtures\Actions\TracedNote;
use Tests\Fixtures\Ai\NotesAgent;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * The cases that need two modules at once, on the shared fixtures: a fake behind a generated route (HTTP and the
 * testing kit), and the kit's assertions on real agent tools (AI and the testing kit).
 */

uses(ActionAssertions::class);

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    $this->user = User::factory()->create();
});

describe('a faked action behind a generated route', function () {
    beforeEach(function () {
        $this->mountRoutes(fn () => Route::middleware(['web', 'auth'])->group(fn () => Actions::routes()));
    });

    it('renders the faked output through the Responder, for JSON and for a browser visit', function () {
        $fake = Actions::fake([CreateNote::class => ['id' => 7, 'title' => 'Faked']]);

        $this->actingAs($this->user)
            ->postJson('/actions/create-note', ['title' => 'Hi', 'body' => 'x'])
            ->assertOk()
            ->assertExactJson(['id' => 7, 'title' => 'Faked']);

        $this->from('/notes/new')
            ->post('/actions/create-note', ['title' => 'Again', 'body' => 'x'])
            ->assertStatus(303)
            ->assertRedirect('/notes/new')
            ->assertSessionHas('action', ['name' => 'create-note', 'output' => ['id' => 7, 'title' => 'Faked']]);

        $fake->assertRanTimes(CreateNote::class, 2);
        $fake->assertRan('create-note', fn (array $input): bool => $input['title'] === 'Again');

        expect(Post::query()->count())->toBe(0);
    });

    it('renders a faked refusal and an unlisted action\'s empty answer', function () {
        Actions::fake([CreateNote::class => Refusal::make('Not today.')->on('title')]);

        $this->actingAs($this->user)
            ->postJson('/actions/create-note', ['title' => 'Hi', 'body' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.title.0', 'Not today.');

        $this->postJson('/actions/list-notes')->assertOk()->assertExactJson([]);
    });

    it('still refuses a guest, because generated routes need a user', function () {
        Actions::fake();

        $this->postJson('/actions/list-notes')->assertUnauthorized();
    });

    it('leaves the real action untouched once the fake is gone', function () {
        Actions::fake([ListNotes::class => ['posts' => []]]);

        $this->app->forgetInstance(InterceptsActions::class);

        $this->actingAs($this->user)
            ->postJson('/actions/create-note', ['title' => 'Real', 'body' => 'x'])
            ->assertOk()
            ->assertJsonPath('title', 'Real');
    });
});

it('hands a faked call on a hand-written route to the fake before the action\'s own gates and rules', function () {
    Trace::reset();
    TracedNote::reset();
    TracedNote::$authorizes = false;

    $this->mountRoutes(fn () => Route::middleware('auth')->post('traced', TracedNote::class));

    $fake = Actions::fake([TracedNote::class => ['title' => 'Faked']]);

    $this->actingAs($this->user)
        ->postJson('/traced', ['title' => str_repeat('x', 30)])
        ->assertOk()
        ->assertExactJson(['title' => 'Faked']);

    $fake->assertRanTimes(TracedNote::class, 1);

    expect(Trace::$calls)->toBe([]);
});

describe('the testing kit on real agent tools', function () {
    beforeEach(function () {
        Auth::shouldUse('web');
    });

    it('passes toContainActionTool for a tool the agent holds', function () {
        $this->skipUnlessAi();

        expect((new NotesAgent($this->user))->tools())->toContainActionTool('create-note');
    });

    it('fails toContainActionTool naming the tools the agent holds', function () {
        $this->skipUnlessAi();

        expect(fn () => expect((new NotesAgent($this->user))->tools())->toContainActionTool('nope'))
            ->toThrow(ExpectationFailedException::class, 'No action tool [nope] among: create-note, late-authorize, list-notes, team-note, translated-note');
    });

    it('audits the agent\'s real tools through the trait', function () {
        $this->skipUnlessAi();

        $this->assertAgentTools(new NotesAgent($this->user));
    });
});
