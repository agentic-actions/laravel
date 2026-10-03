<?php

namespace Tests\Fixtures\Discovery\Plain;

/**
 * An app class that never names the package, so the scanner never loads it.
 */
final class DomainService
{
    /**
     * Do some domain work.
     */
    public function handle(): string
    {
        return 'done';
    }
}
