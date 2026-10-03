<?php

namespace Tests\Fixtures\Mcp;

use Closure;
use Laravel\Mcp\Server\Contracts\Transport;
use LogicException;

/**
 * A transport that hands a message to the server and keeps what it sends back, for the laravel/mcp contract pins.
 */
final class CapturingTransport implements Transport
{
    /**
     * The messages the server sent, in order.
     *
     * @var list<string>
     */
    public array $sent = [];

    /**
     * The server's handler.
     */
    private ?Closure $handler = null;

    /**
     * Keep the server's handler.
     */
    public function onReceive(Closure $handler): void
    {
        $this->handler = $handler;
    }

    /**
     * Hand one raw message to the server.
     */
    public function receive(string $message): void
    {
        ($this->handler ?? throw new LogicException('The server has not started.'))($message);
    }

    /**
     * Keep a message the server sent.
     */
    public function send(string $message): void
    {
        $this->sent[] = $message;
    }

    /**
     * Not served over HTTP.
     */
    public function run(): never
    {
        throw new LogicException('Not served.');
    }

    /**
     * Run a stream's callback at once.
     */
    public function stream(Closure $stream): void
    {
        $stream();
    }
}
