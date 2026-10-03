<?php

namespace AgenticActions\Exceptions;

use LogicException;

/**
 * A schema uses a shape the rule compiler does not turn into Laravel rules.
 *
 * @internal
 */
final class UnsupportedSchema extends LogicException
{
    /**
     * Create the exception for a path and a reason, optionally naming the class.
     */
    private function __construct(
        private readonly string $path,
        private readonly string $reason,
        ?string $class = null,
    ) {
        parent::__construct(($class === null ? '' : "{$class}: ")."[{$path}] {$reason}");
    }

    /**
     * The schema at this dot path is not supported.
     */
    public static function at(string $path, string $reason): self
    {
        return new self($path, $reason);
    }

    /**
     * The same exception, naming the action class that declared the schema.
     */
    public function withClass(string $class): self
    {
        return new self($this->path, $this->reason, $class);
    }
}
