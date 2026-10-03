<?php

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Contracts\ReadsTokenGrants;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Security\TokenCheck;
use AgenticActions\Security\TokenGrants;
use AgenticActions\Surface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\TransientToken;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\DeleteNote;
use Tests\Fixtures\Actions\ListNotes;
use Tests\Fixtures\Actions\PublishNote;
use Tests\Fixtures\Actions\TeamNote;
use Tests\Fixtures\Auth\StatefulTokenGuard;
use Tests\Fixtures\Misconfigured\UndeclaredEffect;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/**
 * Whether the token check lets the context reach the action.
 *
 * @param  class-string<Action>  $class
 */
function tokenAllows(ActionContext $context, string $class): bool
{
    return app(TokenCheck::class)->allows($context, ClassExposure::of($class));
}

/**
 * A user acting through a real Sanctum token with the given abilities, on the sanctum guard.
 *
 * @param  list<string>  $abilities
 */
function tokenUser(array $abilities): User
{
    $user = User::factory()->create();
    $user->withAccessToken($user->createToken('test', $abilities)->accessToken);

    Auth::shouldUse('sanctum');

    return $user;
}

/**
 * Collect the notices the log receives.
 *
 * @return ArrayObject<int, string>
 */
function loggedNotices(): ArrayObject
{
    $notices = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($notices): void {
        if ($event->level === 'notice') {
            $notices->append($event->message);
        }
    });

    return $notices;
}

it('gives a session full access', function () {
    Auth::shouldUse('web');

    expect(tokenAllows(ActionContext::http(User::factory()->create()), DeleteNote::class))->toBeTrue();
});

it('gives Sanctum\'s SPA cookie full access', function () {
    $user = User::factory()->create()->withAccessToken(new TransientToken);
    Auth::shouldUse('sanctum');

    expect(tokenAllows(ActionContext::http($user), PublishNote::class))->toBeTrue();
});

it('lets a default ["*"] token reach every effect on HTTP', function () {
    $context = ActionContext::http(tokenUser(['*']));

    foreach ([ListNotes::class, CreateNote::class, DeleteNote::class, PublishNote::class] as $class) {
        expect(tokenAllows($context, $class))->toBeTrue();
    }
});

it('does not count "*" while an MCP request is bound', function () {
    $context = ActionContext::http(tokenUser(['*']));
    app()->instance('mcp.request', new stdClass);

    expect(tokenAllows($context, CreateNote::class))->toBeFalse();

    $context = ActionContext::http(tokenUser(['actions:write']));

    expect(tokenAllows($context, CreateNote::class))->toBeTrue();
});

it('refuses a session while an MCP request is bound', function () {
    Auth::shouldUse('web');
    app()->instance('mcp.request', new stdClass);

    expect(tokenAllows(ActionContext::http(User::factory()->create()), CreateNote::class))->toBeFalse();
});

it('needs the literal ability for each effect', function (string $ability, array $reaches) {
    $context = ActionContext::http(tokenUser([$ability]));

    foreach ([ListNotes::class, CreateNote::class, DeleteNote::class, PublishNote::class] as $class) {
        expect(tokenAllows($context, $class))->toBe(in_array($class, $reaches, true), "{$ability} on {$class}");
    }
})->with([
    'read' => ['actions:read', [ListNotes::class]],
    'write' => ['actions:write', [CreateNote::class]],
    'destructive' => ['actions:destructive', [DeleteNote::class]],
    'external' => ['actions:external', [PublishNote::class]],
    'another ability' => ['posts:create', []],
]);

it('refuses an undeclared effect whatever the token grants', function () {
    expect(tokenAllows(ActionContext::http(tokenUser(['*'])), UndeclaredEffect::class))->toBeFalse();
});

it('binds a token to a tenant by primary key, with a slug-keyed team', function () {
    $this->useTeamTenancy();

    $team = Team::factory()->create(['slug' => 'acme']);
    $other = Team::factory()->create(['slug' => 'other']);
    $user = tokenUser(['actions:write', 'tenant:'.$team->getKey()]);

    expect(tokenAllows(ActionContext::http($user, $team), TeamNote::class))->toBeTrue()
        ->and(tokenAllows(ActionContext::http($user, $other), TeamNote::class))->toBeFalse()
        ->and(tokenAllows(ActionContext::http(tokenUser(['actions:write', 'tenant:acme']), $team), TeamNote::class))->toBeFalse();
});

it('refuses a tenant-bound token on an action without a tenant', function () {
    $team = Team::factory()->create();

    expect(tokenAllows(ActionContext::http(tokenUser(['*', 'tenant:'.$team->getKey()])), CreateNote::class))->toBeFalse();
});

it('reads Sanctum::actingAs() exactly like a real token', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user, ['*']);

    expect(tokenAllows(ActionContext::http($user), CreateNote::class))->toBeTrue();

    Sanctum::actingAs($user, ['actions:read']);

    expect(tokenAllows(ActionContext::http($user), ListNotes::class))->toBeTrue()
        ->and(tokenAllows(ActionContext::http($user), CreateNote::class))->toBeFalse();
});

it('does not read a StatefulGuard that is not the session guard as a session', function () {
    $user = User::factory()->create();

    Auth::extend('stateful-token', fn () => new StatefulTokenGuard($user));
    config(['auth.guards.stateful' => ['driver' => 'stateful-token']]);
    Auth::shouldUse('stateful');

    expect(app(TokenGrants::class)->grants($user, Auth::guard('stateful')))->toBe([])
        ->and(tokenAllows(ActionContext::http($user), CreateNote::class))->toBeFalse();
});

it('reads a viaRequest guard as nothing and logs one notice naming it', function () {
    $user = User::factory()->create();
    $notices = loggedNotices();

    Auth::viaRequest('api-key', fn () => $user);
    config(['auth.guards.api-key' => ['driver' => 'api-key']]);
    Auth::shouldUse('api-key');

    expect(tokenAllows(ActionContext::http($user), CreateNote::class))->toBeFalse()
        ->and(tokenAllows(ActionContext::http($user), ListNotes::class))->toBeFalse()
        ->and($notices->getArrayCopy())->toBe([
            'agentic-actions: the [api-key] guard is unknown to the token reader, so every action reads as not found for its credentials. Bind AgenticActions\\Contracts\\ReadsTokenGrants to read its grants.',
        ]);
});

it('refuses a context built while no default guard is configured', function () {
    config(['auth.defaults.guard' => null]);

    $context = ActionContext::http(User::factory()->create());

    expect($context->guard)->toBeNull()
        ->and(tokenAllows($context, CreateNote::class))->toBeFalse();
});

it('skips the check on the console and for system work', function () {
    config(['auth.defaults.guard' => null]);

    expect(tokenAllows(ActionContext::console(null, null, 'en', null), DeleteNote::class))->toBeTrue()
        ->and(tokenAllows(ActionContext::system(), DeleteNote::class))->toBeTrue();
});

describe('the Mcp surface', function () {
    it('never counts "*", even with no MCP request bound', function () {
        $context = ActionContext::mcp(tokenUser(['*']), null);

        expect(app()->bound('mcp.request'))->toBeFalse()
            ->and(tokenAllows($context, ListNotes::class))->toBeFalse()
            ->and(tokenAllows($context, CreateNote::class))->toBeFalse();
    });

    it('refuses a session', function () {
        Auth::shouldUse('web');

        expect(tokenAllows(ActionContext::mcp(User::factory()->create(), null), ListNotes::class))->toBeFalse();
    });

    it('allows a literal ability for its effect only', function () {
        $context = ActionContext::mcp(tokenUser(['actions:write']), null);

        expect(tokenAllows($context, CreateNote::class))->toBeTrue()
            ->and(tokenAllows($context, ListNotes::class))->toBeFalse();
    });
});

describe('the Queue surface', function () {
    it('lets null grants reach every effect, with no guard', function () {
        $context = ActionContext::queued(Surface::Http, User::factory()->create(), null, 'en', [], null, null);

        expect($context->guard)->toBeNull();

        foreach ([ListNotes::class, CreateNote::class, DeleteNote::class, PublishNote::class] as $class) {
            expect(tokenAllows($context, $class))->toBeTrue();
        }
    });

    it('decides on the captured grants, with "*" counting', function () {
        $user = User::factory()->create();
        $read = ActionContext::queued(Surface::Http, $user, null, 'en', [], ['actions:read'], null);
        $all = ActionContext::queued(Surface::Http, $user, null, 'en', [], ['*'], null);

        expect(tokenAllows($read, ListNotes::class))->toBeTrue()
            ->and(tokenAllows($read, CreateNote::class))->toBeFalse()
            ->and(tokenAllows($all, CreateNote::class))->toBeTrue()
            ->and(tokenAllows(ActionContext::queued(Surface::Http, $user, null, 'en', [], [], null), ListNotes::class))->toBeFalse();
    });

    it('compares a tenant: grant with the restored tenant\'s primary key', function () {
        $this->useTeamTenancy();

        $user = User::factory()->create();
        $team = Team::factory()->create();
        $other = Team::factory()->create();
        $grants = ['actions:write', 'tenant:'.$team->getKey()];

        expect(tokenAllows(ActionContext::queued(Surface::Http, $user, $team, 'en', [], $grants, null), TeamNote::class))->toBeTrue()
            ->and(tokenAllows(ActionContext::queued(Surface::Http, $user, $other, 'en', [], $grants, null), TeamNote::class))->toBeFalse();
    });
});

describe('capture()', function () {
    it('keeps no token limits for the console and system work', function () {
        expect(app(TokenCheck::class)->capture(ActionContext::console(null, null, 'en', null)))->toBeNull()
            ->and(app(TokenCheck::class)->capture(ActionContext::system()))->toBeNull();
    });

    it('passes a queued run\'s grants on as they are', function () {
        $user = User::factory()->create();

        expect(app(TokenCheck::class)->capture(ActionContext::queued(Surface::Http, $user, null, 'en', [], ['actions:read'], null)))->toBe(['actions:read'])
            ->and(app(TokenCheck::class)->capture(ActionContext::queued(Surface::Http, $user, null, 'en', [], null, null)))->toBeNull();
    });

    it('captures nothing for a context without a guard', function () {
        config(['auth.defaults.guard' => null]);

        expect(app(TokenCheck::class)->capture(ActionContext::http(User::factory()->create())))->toBe([]);
    });

    it('captures what the credential grants on HTTP: null for a session, the token\'s abilities with "*"', function () {
        Auth::shouldUse('web');

        expect(app(TokenCheck::class)->capture(ActionContext::http(User::factory()->create())))->toBeNull()
            ->and(app(TokenCheck::class)->capture(ActionContext::http(tokenUser(['*', 'actions:read']))))->toBe(['*', 'actions:read']);
    });

    it('drops "*" inside MCP, on the Mcp surface or while an MCP request is bound, and reads a session as nothing', function () {
        expect(app(TokenCheck::class)->capture(ActionContext::mcp(tokenUser(['*', 'actions:read']), null)))->toBe(['actions:read']);

        $context = ActionContext::http(tokenUser(['*', 'actions:write', 'tenant:7']));
        app()->instance('mcp.request', new stdClass);

        expect(app(TokenCheck::class)->capture($context))->toBe(['actions:write', 'tenant:7']);

        Auth::shouldUse('web');

        expect(app(TokenCheck::class)->capture(ActionContext::mcp(User::factory()->create(), null)))->toBe([]);
    });
});

it('lets a custom reader bound in the container win, with no notice', function () {
    $user = User::factory()->create();
    $notices = loggedNotices();

    app()->instance(ReadsTokenGrants::class, new ApiKeyGrants);
    Auth::viaRequest('api-key', fn () => $user);
    config(['auth.guards.api-key' => ['driver' => 'api-key']]);
    Auth::shouldUse('api-key');

    expect(tokenAllows(ActionContext::http($user), CreateNote::class))->toBeTrue()
        ->and(tokenAllows(ActionContext::http($user), ListNotes::class))->toBeFalse()
        ->and($notices->getArrayCopy())->toBe([]);
});

it('describes a guard from its configured driver alone', function () {
    config([
        'auth.guards.api-key' => ['driver' => 'api-key'],
        'auth.guards.passport' => ['driver' => 'passport', 'provider' => 'users'],
        'auth.guards.sanctum' => ['driver' => 'sanctum', 'provider' => null],
    ]);

    $grants = app(TokenGrants::class);

    expect($grants->describe('web'))->toBe('session')
        ->and($grants->describe('sanctum'))->toBe('sanctum')
        ->and($grants->describe('api-key'))->toBe('unknown')
        ->and($grants->describe('passport'))->toBe('passport')
        ->and($grants->describe('nothing'))->toBe('unknown')
        ->and(fn () => Auth::guard('api-key'))->toThrow(InvalidArgumentException::class);
});

/**
 * An app's reader for its API-key guard: every key may write.
 */
final class ApiKeyGrants implements ReadsTokenGrants
{
    public function grants(?Authenticatable $actor, Guard $guard): ?array
    {
        return ['actions:write'];
    }
}
