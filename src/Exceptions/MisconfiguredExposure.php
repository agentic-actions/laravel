<?php

namespace AgenticActions\Exceptions;

use LogicException;
use Throwable;

/**
 * An action names a surface the exposure rules refuse, or offers agents a key they may never see.
 *
 * @internal
 */
final class MisconfiguredExposure extends LogicException
{
    /**
     * One line per error, each "{class}: {surface}: {reason}". The exception that caused one of them, when one did,
     * stays attached as the previous exception, so its class, file and trace are not lost.
     *
     * @param  list<string>  $errors
     */
    public static function withErrors(array $errors, ?Throwable $previous = null): self
    {
        return new self(implode("\n", $errors), 0, $previous);
    }
}
