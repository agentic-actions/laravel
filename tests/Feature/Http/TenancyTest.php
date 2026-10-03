<?php

namespace Tests\Feature\Http;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Contracts\Tenancy;
use AgenticActions\Effect;
use AgenticActions\Facades\Actions;
use AgenticActions\Tenancy\SpatieTeams;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * Tenant groups: the route names the team by its route key, membership decides with the effect, and a foreign or
 * unknown team reads exactly like an unknown action.
 */

beforeEach(function () {
    TeamProbe::$teamDuringAuthorize = null;

    config(['agentic-actions.discovery.classes' => [TeamProbe::class]]);
    $this->useTeamTenancy();

    $this->mountRoutes(fn () => Route::middleware('auth')->prefix('teams/{team}')->name('teams.')->group(
        fn () => Actions::routes(tenant: true),
    ));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create(['slug' => 'acme']);
    $this->team->users()->attach($this->user);
});

it('lets a member reach the team\'s actions by slug', function () {
    $this->actingAs($this->user)
        ->postJson('/teams/acme/actions/team-note', ['title' => 'Hi'])
        ->assertOk();

    expect(Post::query()->value('team_id'))->toBe($this->team->getKey());
});

it('answers a non-member and an unknown slug with the same 404 body', function () {
    $stranger = User::factory()->create();

    $foreign = $this->actingAs($stranger)->postJson('/teams/acme/actions/team-note', ['title' => 'Hi'])->assertNotFound();
    $unknown = $this->actingAs($this->user)->postJson('/teams/no-such-team/actions/team-note', ['title' => 'Hi'])->assertNotFound();

    expect($foreign->getContent())->toBe($unknown->getContent())
        ->and($foreign->json())->toBe(['message' => trans('agentic-actions::http.not_found')])
        ->and(Post::query()->count())->toBe(0);
});

it('hands membership the effect: an archived team still reads, and refuses writes', function () {
    $archived = Team::factory()->create(['slug' => 'archived-acme']);
    $archived->users()->attach($this->user);

    Post::factory()->for($this->user)->create(['title' => 'Old', 'team_id' => $archived->getKey()]);

    $this->actingAs($this->user)
        ->postJson('/teams/archived-acme/actions/list-notes')
        ->assertOk()
        ->assertJsonPath('posts.0.title', 'Old');

    $this->actingAs($this->user)
        ->postJson('/teams/archived-acme/actions/team-note', ['title' => 'New'])
        ->assertNotFound();

    expect(Post::query()->count())->toBe(1);
});

it('runs authorize() with the spatie team set to the tenant, and restores it after', function () {
    config(['agentic-actions.tenancy' => SpatieTeams::class]);
    $this->app->forgetInstance(Tenancy::class);

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId(null);

    $this->actingAs($this->user)
        ->postJson('/teams/acme/actions/team-probe')
        ->assertOk()
        ->assertExactJson(['team' => $this->team->getKey()]);

    expect(TeamProbe::$teamDuringAuthorize)->toBe($this->team->getKey())
        ->and($registrar->getPermissionsTeamId())->toBeNull();
});

/**
 * Reports the permission team its hooks see.
 */
#[Expose(web: true)]
final class TeamProbe extends Action
{
    /**
     * The permission team authorize() saw.
     */
    public static int|string|null $teamDuringAuthorize = null;

    protected ?Effect $effect = Effect::Read;

    /**
     * The team handle() saw.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['team' => $schema->integer()->required()];
    }

    /**
     * Record the permission team, then allow a member.
     */
    public function authorize(ActionContext $context): bool
    {
        self::$teamDuringAuthorize = app(PermissionRegistrar::class)->getPermissionsTeamId();

        return $context->actor !== null;
    }

    /**
     * The permission team during handle().
     *
     * @return array{team: int|string|null}
     */
    public function handle(ActionContext $context): array
    {
        return ['team' => app(PermissionRegistrar::class)->getPermissionsTeamId()];
    }
}
