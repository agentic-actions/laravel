<?php

namespace AgenticActions\Streaming;

use AgenticActions\Effect;
use Closure;
use Generator;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\ToolResult;
use Laravel\Ai\Streaming\Protocols\VercelDataProtocol;
use LogicException;
use ReflectionFunction;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The Vercel UI message stream v1, built from an allowlist of part types, with a live data-action row for each tool.
 * A part type the allowlist does not name never leaves the server. A call that waits for a person's confirmation
 * crosses as an input-less tool part and its approval request, and a call a confirmation resumed as its settled part
 * with no output; no tool part ever carries input or output. A table a call shows follows its row as a data-view part
 * built from the action's declared columns, at most ten a turn. A closed tab never stops the turn: every tool still
 * runs and the turn is stored.
 *
 * @api
 */
final class ActionsProtocol extends VercelDataProtocol
{
    /**
     * The part types the package writes, which a host part may not use.
     */
    private const RESERVED = ['data-action', 'data-approval', 'data-elicitation', 'data-view'];

    /**
     * The most tables one turn shows.
     */
    private const VIEWS = 10;

    /**
     * The protocol streaming in this process now.
     */
    private static ?self $current = null;

    /**
     * The run this protocol streams.
     */
    private ?string $turn = null;

    /**
     * The response this protocol streams, which knows the conversation a new one opens.
     */
    private ?StreamableAgentResponse $response = null;

    /**
     * The ids of the tables this turn has shown.
     *
     * @var array<string, true>
     */
    private array $views = [];

    /**
     * The locale the stream started in.
     */
    private string $locale = '';

    /**
     * The rows shown and not yet closed, by tool invocation.
     *
     * @var array<string, array{action: string, running: string, done: ?string, effect: ?string, record: ?ActivityRecord}>
     */
    private array $rows = [];

    /**
     * finish-step and finish, held until the host's parts are out.
     *
     * @var list<array<string, mixed>>
     */
    private array $held = [];

    /**
     * Whether the provider reported an error inside the stream.
     */
    private bool $providerError = false;

    /**
     * The tool names of the calls started in this stream, by tool-call id.
     *
     * @var array<string, string>
     */
    private array $toolNames = [];

    /**
     * Create the protocol.
     *
     * @param  (Closure(StreamableAgentResponse, bool): iterable<int, mixed>)|null  $after  the host's own data-* parts, sent after the rows close on both paths; the bool is true when the turn failed
     * @param  string|null  $messageId  ChatRequest::messageId(): the assistant message a confirmation continues
     */
    public function __construct(private readonly ?Closure $after = null, ?string $messageId = null)
    {
        parent::__construct($messageId);
    }

    /**
     * The protocol streaming the given run now, or the one streaming any run when the id is null.
     *
     * @internal
     */
    public static function current(?string $invocationId = null): ?self
    {
        $protocol = self::$current;

        return $protocol !== null && ($invocationId === null || $protocol->turn === $invocationId) ? $protocol : null;
    }

    /**
     * Open a row for a tool about to run and return its running part. Both labels were read before the tool ran.
     *
     * @internal
     *
     * @return array{type: string, id: string, data: array<string, mixed>}
     */
    public function start(string $toolInvocationId, string $action, string $label, ?string $finishedLabel, ?Effect $effect): array
    {
        $this->rows[$toolInvocationId] = ['action' => $action, 'running' => $label, 'done' => $finishedLabel, 'effect' => $effect?->value, 'record' => null];

        return $this->row($toolInvocationId, ['action' => $action, 'label' => $label, 'status' => 'running', 'effect' => $effect?->value]);
    }

    /**
     * Whether a row is open for this tool invocation.
     *
     * @internal
     */
    public function open(string $toolInvocationId): bool
    {
        return isset($this->rows[$toolInvocationId]);
    }

    /**
     * Whether this turn has shown as many tables as a turn may, ten.
     *
     * @internal
     */
    public function full(): bool
    {
        return count($this->views) >= self::VIEWS;
    }

    /**
     * The id of the conversation this turn belongs to when laravel/ai opens a new one for it, known before the turn
     * ends; null otherwise.
     *
     * @internal
     */
    public function conversationId(): ?string
    {
        return $this->response?->conversationId;
    }

    /**
     * Keep what an open row's tool reported, the worst report winning. The record is built only for an open row; any
     * other tool invocation (a sub-agent's, a tool with no row) is ignored.
     *
     * @internal
     *
     * @param  Closure(): ActivityRecord  $record
     */
    public function record(string $toolInvocationId, Closure $record): void
    {
        if (! isset($this->rows[$toolInvocationId])) {
            return;
        }

        $built = $record();
        $previous = $this->rows[$toolInvocationId]['record'];

        if ($previous === null || $built->rank() > $previous->rank()) {
            $this->rows[$toolInvocationId]['record'] = $built;
        }
    }

    /**
     * Close a row and return its parts: its final part, then the table it showed when it is done and its record holds
     * one, while the turn has shown fewer than ten. None when no row is open for the tool invocation.
     *
     * @internal
     *
     * @return list<array<string, mixed>>
     */
    public function finish(string $toolInvocationId, bool $threw): array
    {
        $row = $this->rows[$toolInvocationId] ?? null;

        if ($row === null) {
            return [];
        }

        unset($this->rows[$toolInvocationId]);

        $record = $row['record'];
        $status = $threw ? 'failed' : ($record->status ?? 'ended');   // ended: it ran and reported nothing, so nothing is claimed
        $locale = $this->locale === '' ? null : $this->locale;
        $view = $status === 'done' && ! $this->full() ? $record?->view : null;
        $id = is_string($view['id'] ?? null) ? $view['id'] : null;

        // A provider that repeats a tool-call id would give two tables one id, and the page shows a part per id: the
        // first stands, and the second is left out.
        if ($id === null || isset($this->views[$id])) {
            $view = null;
        } else {
            $this->views[$id] = true;
        }

        $parts = [$this->row($toolInvocationId, [
            'action' => $row['action'],
            'label' => $status === 'done' ? ($row['done'] ?? $row['running']) : $row['running'],
            'status' => $status,
            'effect' => $row['effect'],
            'note' => match ($status) {
                'refused' => (string) trans('agentic-actions::activity.refused', [], $locale),
                'failed' => (string) trans('agentic-actions::activity.failed', [], $locale),
                default => null,
            },
            'touches' => $status === 'done' ? $record?->touches : null,
            'link' => $status === 'done' ? $record?->link : null,
        ])];

        return $view === null ? $parts : [...$parts, $view];
    }

    /**
     * Write one data-action, data-approval, data-elicitation or data-view part now, between two stream events, and flush
     * it through PHP's buffers. Any other part is reported and dropped.
     *
     * @internal
     *
     * @param  array<string, mixed>  $part
     */
    public function relay(array $part): void
    {
        if (! in_array($part['type'] ?? null, self::RESERVED, true)) {
            self::reportSafely(new LogicException('relay() writes data-action, data-approval, data-elicitation and data-view parts only; one was dropped.'));

            return;
        }

        foreach ($this->yieldPart($part) as $frame) {
            echo $this->encode($frame);
        }

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    /**
     * laravel/ai's streamed response, whose body echoes and flushes each part, so relay()'s rows stay in order with it.
     *
     * @upstream The package echoes and flushes each part itself rather than handing the server a generator.
     */
    public function response(StreamableAgentResponse $response): Response
    {
        $streamed = parent::response($response);
        $parts = $streamed instanceof StreamedResponse ? $streamed->getCallback() : null;

        if ($parts !== null && (new ReflectionFunction($parts))->isGenerator()) {
            $streamed->setCallback(function () use ($parts): void {
                foreach ($parts() as $chunk) {
                    echo $chunk;

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }

                    flush();
                }
            });
        }

        return $streamed;
    }

    /**
     * The stream's headers, with proxy buffering off.
     *
     * @upstream The protocol sends X-Accel-Buffering: no itself.
     *
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [...parent::headers(), 'X-Accel-Buffering' => 'no'];
    }

    /**
     * laravel/ai's result part plus the tool's name, which toolParts() reads for a declined row and never sends.
     *
     * @return array<string, mixed>
     */
    protected function toolResultPart(ToolResult $event): array
    {
        return [...parent::toolResultPart($event), 'toolName' => $event->toolResult->name];
    }

    /**
     * The allowlisted parts as they happen, then the rows still open, the host's parts, and finish or the one error.
     *
     * @return Generator<int, array<string, mixed>>
     */
    protected function parts(StreamableAgentResponse $response): Generator
    {
        // A closed tab must not stop the turn: every tool runs, the turn is stored, and later writes go nowhere.
        ignore_user_abort(true);

        $previous = self::$current;
        self::$current = $this;
        $this->turn = $response->invocationId;
        $this->response = $response;
        $this->views = [];
        $this->locale = app()->getLocale();
        $this->rows = [];
        $this->held = [];
        $this->providerError = false;
        $this->toolNames = [];

        try {
            foreach (parent::parts($response) as $part) {
                $type = $part['type'] ?? null;

                if ($type === 'error') {
                    $this->providerError = true;   // replaced by the one fixed sentence, sent last

                    continue;
                }

                if ($type === 'finish-step' || $type === 'finish') {
                    $this->held[] = $this->redact($part) ?? ['type' => $type];

                    continue;
                }

                if ($type === 'start-step') {
                    yield from $this->release();
                }

                if (($tool = $this->toolParts($part)) !== null) {
                    yield from $tool;

                    continue;
                }

                if (($redacted = $this->redact($part)) !== null) {
                    yield $redacted;
                }
            }
        } catch (Throwable $exception) {
            $this->exception = $exception;

            // Reported here, once, and not rethrown: nothing is left for response() to report, so a reporter that
            // throws cannot cut the stream short, and the provider's own message is reported although it never
            // reaches the browser.
            self::reportSafely($exception);

            yield from $this->closing($response, failed: true);   // sends the one error part itself

            return;
        } finally {
            self::$current = $previous;
        }

        yield from $this->closing($response, failed: $this->providerError);
    }

    /**
     * The one error part, with the fixed sentence.
     *
     * @return Generator<int, array<string, mixed>>
     */
    protected function maskedErrorParts(): Generator
    {
        yield from $this->yieldPart(['type' => 'error', 'errorText' => $this->sentence()]);
    }

    /**
     * Rows still open close as ended, never with a table, then the host's parts, then the held finish parts, or the one
     * error part last.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function closing(StreamableAgentResponse $response, bool $failed): Generator
    {
        try {
            foreach (array_keys($this->rows) as $toolInvocationId) {
                foreach (array_slice($this->finish($toolInvocationId, threw: false), 0, 1) as $part) {
                    yield from $this->yieldPart($part);
                }
            }

            foreach ($this->after === null ? [] : ($this->after)($response, $failed) as $part) {
                if (($host = $this->hostPart($part)) !== null) {
                    yield from $this->yieldPart($host);
                }
            }
        } catch (Throwable $exception) {
            self::reportSafely($exception);   // a host closure that throws must not break the turn it closes
        }

        if ($failed) {
            $this->errored = true;
            $this->held = [];

            yield from $this->maskedErrorParts();

            return;
        }

        yield from $this->release();
    }

    /**
     * The allowlist: each part rebuilt from the keys it may carry, or null to drop it.
     *
     * @param  array<string, mixed>  $part
     * @return array<string, mixed>|null
     */
    private function redact(array $part): ?array
    {
        $type = $part['type'] ?? null;
        $id = $part['id'] ?? null;

        return match (true) {
            $type === 'start' => is_string($part['messageId'] ?? null) ? ['type' => 'start', 'messageId' => $part['messageId']] : ['type' => 'start'],
            $type === 'start-step', $type === 'finish-step' => ['type' => $type],
            ($type === 'text-start' || $type === 'text-end') && is_string($id) => ['type' => $type, 'id' => $id],
            $type === 'text-delta' && is_string($id) && is_string($part['delta'] ?? null) => ['type' => 'text-delta', 'id' => $id, 'delta' => $part['delta']],
            $type === 'finish' => is_string($part['finishReason'] ?? null) ? ['type' => 'finish', 'finishReason' => $part['finishReason']] : ['type' => 'finish'],
            default => null,   // a malformed part, reasoning-*, source-*, custom, file, and any later type
        };
    }

    /**
     * What a tool part may become. For a call of this stream: nothing, except a paused call's input-less tool part and
     * its approval request, whose id is the tool-call id ChatRequest reads answers by. For a call a confirmation
     * resumed: its settled part with no output, and a declined row. Null for a part that is not a tool part.
     *
     * @upstream The package sends no tool input or output to the browser.
     *
     * @param  array<string, mixed>  $part
     * @return list<array<string, mixed>>|null
     */
    private function toolParts(array $part): ?array
    {
        $type = $part['type'] ?? null;
        $id = $part['toolCallId'] ?? null;

        if (! is_string($type) || ! str_starts_with($type, 'tool-')) {
            return null;
        }

        if (! is_string($id)) {
            return [];
        }

        if ($type === 'tool-input-available') {
            $this->toolNames[$id] = is_string($part['toolName'] ?? null) ? $part['toolName'] : '';

            return [];
        }

        if ($type === 'tool-approval-request') {
            return ($this->toolNames[$id] ?? '') === '' ? [] : [
                ['type' => 'tool-input-available', 'toolCallId' => $id, 'toolName' => $this->toolNames[$id], 'input' => new stdClass],
                ['type' => 'tool-approval-request', 'toolCallId' => $id, 'approvalId' => $id],
            ];
        }

        // A result of a call this stream did not start is a resumed one, passed on only when continuing its message.
        if ($this->messageId === null || isset($this->toolNames[$id]) || ($part['preliminary'] ?? false) !== false) {
            return [];
        }

        $locale = $this->locale === '' ? null : $this->locale;

        return match ($type) {
            'tool-output-available' => [['type' => $type, 'toolCallId' => $id, 'output' => null]],
            'tool-output-error' => [['type' => $type, 'toolCallId' => $id, 'errorText' => (string) trans('agentic-actions::activity.failed', [], $locale)]],
            'tool-output-denied' => [['type' => $type, 'toolCallId' => $id], $this->row($id, [
                'action' => is_string($part['toolName'] ?? null) ? $part['toolName'] : null,
                'label' => (string) trans('agentic-actions::activity.declined', [], $locale),
                'status' => 'declined',
            ])],
            default => [],
        };
    }

    /**
     * A host part narrowed to type, id and data, or null (reported) when it is not a data-* part the host may send.
     *
     * @return array<string, mixed>|null
     */
    private function hostPart(mixed $part): ?array
    {
        $type = is_array($part) ? ($part['type'] ?? null) : null;

        if (! is_array($part) || ! is_string($type) || ! str_starts_with($type, 'data-') || in_array($type, self::RESERVED, true)
            || ! array_key_exists('data', $part) || (array_key_exists('id', $part) && ! is_string($part['id']))) {
            self::reportSafely(new LogicException('A host part must be a data-* part other than data-action, data-approval, data-elicitation or data-view, with a data key and an optional string id; one was dropped.'));

            return null;
        }

        return array_intersect_key($part, ['type' => true, 'id' => true, 'data' => true]);
    }

    /**
     * Send the held finish-step and finish parts.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function release(): Generator
    {
        $held = $this->held;
        $this->held = [];

        yield from $held;
    }

    /**
     * A data-action part, its data without empty keys, for a tool invocation or, for a declined row, a tool call.
     *
     * @param  array<string, mixed>  $data
     * @return array{type: string, id: string, data: array<string, mixed>}
     */
    private function row(string $toolInvocationId, array $data): array
    {
        return ['type' => 'data-action', 'id' => 'a:'.$toolInvocationId, 'data' => array_filter($data, fn (mixed $value): bool => $value !== null && $value !== [])];
    }

    /**
     * Report an exception. A reporter that throws is ignored: the turn it would cut short has already written its
     * rows, and the browser still gets the rest of the stream and [DONE].
     */
    private static function reportSafely(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
            // Nothing more can be done for it here.
        }
    }

    /**
     * The error part's text: the stream.interrupted line, which an app rewords by publishing it.
     */
    private function sentence(): string
    {
        return (string) trans('agentic-actions::stream.interrupted', [], $this->locale === '' ? null : $this->locale);
    }
}
