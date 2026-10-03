<?php

namespace AgenticActions\Pipeline;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\ValidatedInput;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Calls an action's authorize() and reads its answer.
 *
 * @internal
 */
final class Authorizer
{
    /**
     * Whether the action declares authorize() taking ValidatedInput (Late), not taking it (Early), or none (Missing).
     */
    public static function timing(Action $action): AuthorizeTiming
    {
        if (! method_exists($action, 'authorize')) {
            return AuthorizeTiming::Missing;
        }

        foreach ((new ReflectionMethod($action, 'authorize'))->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && is_a($type->getName(), ValidatedInput::class, true)) {
                return AuthorizeTiming::Late;
            }
        }

        return AuthorizeTiming::Early;
    }

    /**
     * Call authorize() through the container and map its result to null (allowed), NotFound or Denied. Any other
     * throwable propagates for the exception mapper.
     */
    public function check(Action $action, ActionContext $context, ?ValidatedInput $input): ?OutcomeKind
    {
        $parameters = [ActionContext::class => $context];

        if ($input !== null) {
            $parameters[ValidatedInput::class] = $input;
        }

        try {
            $result = app()->call([$action, 'authorize'], $parameters);
        } catch (AuthorizationException $exception) {
            return $exception->status() === 404 ? OutcomeKind::NotFound : OutcomeKind::Denied;
        } catch (ModelNotFoundException) {
            return OutcomeKind::NotFound;
        }

        if ($result instanceof Response) {
            return match (true) {
                $result->allowed() => null,
                $result->status() === 404 => OutcomeKind::NotFound,
                default => OutcomeKind::Denied,
            };
        }

        return $result === true ? null : OutcomeKind::Denied;
    }
}
