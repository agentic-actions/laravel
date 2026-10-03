<?php

namespace AgenticActions;

/**
 * What an action does to the world. Write changes the actor's own data, which the actor can enter again, and affects
 * nobody else yet. Destructive removes something other people rely on or the actor cannot recreate. External reaches
 * people or systems outside the actor's own data.
 *
 * @api
 */
enum Effect: string
{
    case Read = 'read';
    case Write = 'write';
    case Destructive = 'destructive';
    case External = 'external';

    /**
     * Whether a model may call this effect without a person confirming the call: Read and Write. False means each call
     * a model makes waits for a person (Destructive and External).
     */
    public function isModelSafe(): bool
    {
        return $this === self::Read || $this === self::Write;
    }
}
