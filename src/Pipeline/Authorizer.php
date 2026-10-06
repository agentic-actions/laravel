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
use ReflectionParameter;
use ReflectionUnionType;

/**
 * Calls an action's authorize() and reads its answer.
 *
 * @internal
 */
final class Authorizer
{
    /**
     * Whether the action declares authorize() taking ValidatedInput (Late), taking a ValidatedInput that may be null
     * (Both: called before the input with null, then after validation with it), not taking it (Early), or none
     * (Missing).
     */
    public static function timing(Action $action): AuthorizeTiming
    {
        if (! method_exists($action, 'authorize')) {
            return AuthorizeTiming::Missing;
        }

        $parameter = self::inputParameter($action);

        if ($parameter === null) {
            return AuthorizeTiming::Early;
        }

        return $parameter->allowsNull() ? AuthorizeTiming::Both : AuthorizeTiming::Late;
    }

    /**
     * Call authorize() through the container and map its result to null (allowed), NotFound or Denied. Any other
     * throwable propagates for the exception mapper. The input is passed by the parameter's name, null included, so a
     * parameter without a default gets the null, and one typed with a subclass of ValidatedInput fails its type rather
     * than fall back to its default.
     */
    public function check(Action $action, ActionContext $context, ?ValidatedInput $input): ?OutcomeKind
    {
        $parameters = [ActionContext::class => $context];

        if (($parameter = self::inputParameter($action)) !== null) {
            $parameters[$parameter->getName()] = $input;
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

    /**
     * The authorize() parameter the input goes to, or null when it has none: one whose declared type, alone or in a
     * union, is ValidatedInput, a subclass of it (the call then fails its type), or a class or interface ValidatedInput
     * is, such as the ValidatedData contract. A parameter typed with that contract is read as taking the input, so it
     * receives it after validation instead of only ever its default null.
     */
    private static function inputParameter(Action $action): ?ReflectionParameter
    {
        foreach ((new ReflectionMethod($action, 'authorize'))->getParameters() as $parameter) {
            $type = $parameter->getType();
            $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];

            foreach ($types as $member) {
                if ($member instanceof ReflectionNamedType && ! $member->isBuiltin() && self::takesInput($member->getName())) {
                    return $parameter;
                }
            }
        }

        return null;
    }

    /**
     * Whether a declared class or interface is ValidatedInput, a subclass of it, or one ValidatedInput is.
     */
    private static function takesInput(string $type): bool
    {
        return is_a($type, ValidatedInput::class, true) || is_a(ValidatedInput::class, $type, true);
    }
}
