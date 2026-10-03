<?php

namespace AgenticActions\Exceptions;

use LogicException;

/**
 * An action names a surface the exposure rules refuse, or offers agents a key they may never see.
 *
 * @internal
 */
final class MisconfiguredExposure extends LogicException
{
    /**
     * One line per error, each "{class}: {surface}: {reason}".
     *
     * @param  list<string>  $errors
     */
    public static function withErrors(array $errors): self
    {
        return new self(implode("\n", $errors));
    }
}
