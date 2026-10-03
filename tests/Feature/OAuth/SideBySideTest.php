<?php

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Tests\Fixtures\OAuth\Flow;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * With auth:sanctum,api, Sanctum tokens behave as in 0.6 and Passport tokens work beside them, on one user model
 * that keeps Sanctum's trait alone.
 */

beforeEach(function () {
    $this->useOAuth(Flow::teams());

    $this->alice = User::factory()->create();
    $this->acme = Team::factory()->create(['slug' => 'acme']);
    $this->other = Team::factory()->create(['slug' => 'other']);
    $this->acme->users()->attach($this->alice);
    $this->other->users()->attach($this->alice);
    $this->flow = new Flow($this);
});

it('serves a Sanctum token bound to a tenant as in 0.6, on the sanctum guard, with no connection query', function () {
    $sanctum = $this->alice->createToken('acme', ['actions:read', 'tenant:'.$this->acme->id])->plainTextToken;
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    expect($this->flow->tools($sanctum, '/mcp/t/acme'))->toBe(['list-team-posts'])
        ->and(app('auth')->getDefaultDriver())->toBe('sanctum')
        ->and($this->flow->tools($sanctum, '/mcp/t/other'))->toBe([])
        ->and($this->flow->tools($sanctum, '/mcp/actions'))->toBe([])
        ->and(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'agentic_mcp_connections') || str_contains($sql, 'oauth_')))->toBe([]);
});

it('serves a Passport token beside it, on the api guard, for the same person and model', function () {
    $passport = $this->flow->connect($this->alice, 'http://localhost/mcp/t/acme');
    $sanctum = $this->alice->createToken('any', ['actions:read'])->plainTextToken;

    expect($this->flow->tools($passport, '/mcp/t/acme'))->toBe(['create-team-post', 'list-team-posts'])
        ->and(app('auth')->getDefaultDriver())->toBe('api')
        ->and($this->flow->tools($sanctum, '/mcp/t/other'))->toBe(['list-team-posts'])
        ->and(app('auth')->getDefaultDriver())->toBe('sanctum')
        ->and(class_uses_recursive(User::class))->toContain(HasApiTokens::class)->not->toContain('Laravel\Passport\HasApiTokens');
});
