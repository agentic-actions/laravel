<?php

namespace Tests\Fixtures\Install;

use Illuminate\Console\Command;

/**
 * Stands in for a command actions:install calls, such as install:api (which runs composer require) or migrate, and
 * records the options each call passed. It does nothing else, and exits with the given code.
 */
final class RecordingCommand extends Command
{
    /**
     * The options of each call, by command name.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    public static array $calls = [];

    /**
     * Stand in for the named command, which takes these options and exits with this code.
     */
    public function __construct(string $name, string $options = '', private readonly int $exitCode = self::SUCCESS)
    {
        $this->signature = trim("{$name} {$options}");
        $this->description = "Records calls to {$name}";

        parent::__construct();
    }

    /**
     * Record the call.
     */
    public function handle(): int
    {
        self::$calls[(string) $this->getName()][] = array_filter($this->options(), fn (mixed $value): bool => $value !== false && $value !== null);

        return $this->exitCode;
    }
}
