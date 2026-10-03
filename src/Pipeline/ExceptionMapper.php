<?php

namespace AgenticActions\Pipeline;

use AgenticActions\ActionContext;
use AgenticActions\ActionsManager;
use AgenticActions\Exceptions\ReadActionWrote;
use AgenticActions\MissingContext;
use AgenticActions\Refusal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Maps a throwable raised by fromAgent(), authorize() or handle() to an outcome kind.
 *
 * @internal
 */
final class ExceptionMapper
{
    /**
     * Map a throwable to an outcome kind. MissingContext and a Read that wrote are always crashes, whatever the app
     * maps with Actions::refuse().
     */
    public function map(Throwable $exception, ActionContext $context): MappedException
    {
        if ($exception instanceof Refusal) {
            return new MappedException(OutcomeKind::Refused, $exception);
        }

        if ($exception instanceof MissingContext || $exception instanceof ReadActionWrote) {
            return new MappedException(OutcomeKind::Failed);
        }

        if (($refusal = app(ActionsManager::class)->refusalFor($exception, $context)) !== null) {
            return new MappedException(OutcomeKind::Refused, $refusal);
        }

        return match (true) {
            $exception instanceof ValidationException => new MappedException(OutcomeKind::Invalid, validation: $exception),
            $exception instanceof ModelNotFoundException => new MappedException(OutcomeKind::NotFound),
            $exception instanceof AuthorizationException => new MappedException(
                $exception->status() === 404 ? OutcomeKind::NotFound : OutcomeKind::Denied,
            ),
            default => new MappedException(OutcomeKind::Failed),
        };
    }
}
