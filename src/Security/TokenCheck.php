<?php

namespace AgenticActions\Security;

use AgenticActions\ActionContext;
use AgenticActions\Contracts\ReadsTokenGrants;
use AgenticActions\Exposure\Entry;
use AgenticActions\Queue\RunAction;
use AgenticActions\Surface;
use Illuminate\Support\Facades\Auth;

/**
 * Token grants follow the guard that authenticated the call and fail closed.
 *
 * @internal
 */
final class TokenCheck
{
    /**
     * Whether the credential behind the context may reach the action.
     */
    public function allows(ActionContext $context, Entry $live): bool
    {
        // The console and system work are the only contexts that skip the check.
        if ($context->surface === Surface::Console || $context->surface === Surface::System) {
            return true;
        }

        // A queued run keeps the grants captured at dispatch; null means its caller had no token limits.
        if ($context->surface === Surface::Queue) {
            return $context->grants === null || $this->decide($context->grants, $live, $context, literal: false);
        }

        // Only console(), system() and queued() build a context without a guard; any other guardless context fails
        // closed.
        if ($context->guard === null) {
            return false;
        }

        $grants = $this->read($context);

        if ($grants === null) {
            return ! self::literal($context);
        }

        return $this->decide($grants, $live, $context, self::literal($context));
    }

    /**
     * The grants a queued run keeps: what the caller's credential grants now, with "*" dropped inside MCP, where it
     * never counts, and a session read as nothing there. Null: no token limits.
     *
     * @return list<string>|null
     */
    public function capture(ActionContext $context): ?array
    {
        return match (true) {
            $context->surface === Surface::Console, $context->surface === Surface::System => null,
            $context->surface === Surface::Queue => $context->grants,
            $context->guard === null => [],
            ! self::literal($context) => $this->read($context),
            default => array_values(array_diff($this->read($context) ?? [], ['*'])),
        };
    }

    /**
     * What the credential of the context's guard grants: null for no token limits (a session), a list otherwise.
     * Callers pass a context with a guard.
     *
     * @return list<string>|null
     */
    private function read(ActionContext $context): ?array
    {
        // Inside a queued run the credential is the one its caller had at dispatch, whatever context the job's own
        // code builds: the worker's guards hold no token, and its session guard would read as no limits.
        if (($run = RunAction::running()) !== null) {
            return $run->grants;
        }

        $guard = (string) $context->guard;
        $reader = app(ReadsTokenGrants::class);
        $grants = $reader->grants($context->actor, Auth::guard($guard));

        if ($reader instanceof TokenGrants && $grants === [] && $reader->describe($guard) === 'unknown') {
            $reader->noticeUnknown($guard);
        }

        return $grants;
    }

    /**
     * Whether these grants reach the action: its effect's ability ("*" counts unless literal), then the tenant binding.
     *
     * @param  list<string>  $grants
     */
    private function decide(array $grants, Entry $live, ActionContext $context, bool $literal): bool
    {
        if ($grants === [] || $live->effect === null) {
            return false;
        }

        $ability = config("agentic-actions.abilities.{$live->effect->value}");

        $granted = $literal
            ? in_array($ability, $grants, true)
            : in_array('*', $grants, true) || in_array($ability, $grants, true);

        if (! $granted) {
            return false;
        }

        $prefix = (string) config('agentic-actions.abilities.tenant');
        $bound = [];

        foreach ($grants as $grant) {
            if ($prefix !== '' && str_starts_with($grant, $prefix)) {
                $bound[] = substr($grant, strlen($prefix));
            }
        }

        // A bound token reaches only its tenants' tenant-scoped actions: never an action without a tenant, nor an
        // account-level one mounted under a tenant's URL.
        if ($bound !== []) {
            return $live->tenantScoped && $context->tenant !== null && in_array((string) $context->tenant->getKey(), $bound, true);
        }

        return true;
    }

    /**
     * Inside MCP: the Mcp surface, any call made while laravel/mcp handles a request, or inside a run MCP queued.
     */
    private static function literal(ActionContext $context): bool
    {
        return $context->surface === Surface::Mcp || app()->bound('mcp.request') || RunAction::running()?->modelOrigin() === Surface::Mcp;
    }
}
