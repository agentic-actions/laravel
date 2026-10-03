<?php

use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Contracts\PaginatesConversations;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Storage\StoredMessage;
use Laravel\Ai\Streaming\Events\ToolResult;
use Laravel\Ai\Streaming\Protocols\StreamProtocol;
use Laravel\Ai\Streaming\Protocols\VercelDataProtocol;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\Approvals\HostToolAgent;
use Workbench\App\Models\User;

/*
 * Every laravel/ai member the streaming module relies on, pinned by reflection, so an upstream rename fails one named
 * test here first.
 */

beforeEach(function () {
    $this->skipUnlessAi();
});

/**
 * A method's visibility, parameters and return type, as "protected name(Type $param = default): Return".
 *
 * PHP 8.5 reflects a `self` return type as the declaring class's name, so that name prints as `self` on every version.
 */
function streamingSignature(string $class, string $method): string
{
    $reflection = new ReflectionMethod($class, $method);

    $parameters = array_map(
        fn (ReflectionParameter $parameter): string => trim(($parameter->getType() ?? '').' $'.$parameter->getName())
            .($parameter->isDefaultValueAvailable() ? ' = '.var_export($parameter->getDefaultValue(), true) : ''),
        $reflection->getParameters(),
    );

    $visibility = match (true) {
        $reflection->isPublic() => 'public',
        $reflection->isProtected() => 'protected',
        default => 'private',
    };

    $return = (string) ($reflection->getReturnType() ?? 'mixed');

    if ($return === $reflection->getDeclaringClass()->getName()) {
        $return = 'self';
    }

    return $visibility.($reflection->isStatic() ? ' static' : '').' '.$method.'('.implode(', ', $parameters).'): '.$return;
}

/**
 * A property's visibility and type, as "protected ?Type".
 */
function streamingProperty(string $class, string $property): string
{
    $reflection = new ReflectionProperty($class, $property);

    return ($reflection->isPublic() ? 'public' : ($reflection->isProtected() ? 'protected' : 'private')).' '.$reflection->getType();
}

it('pins the protocol members ActionsProtocol overrides and calls', function () {
    expect(streamingSignature(VercelDataProtocol::class, 'parts'))->toBe('protected parts('.StreamableAgentResponse::class.' $response): Generator')
        ->and(streamingSignature(VercelDataProtocol::class, 'yieldPart'))->toBe('protected yieldPart(array $part): Generator')
        ->and(streamingSignature(VercelDataProtocol::class, 'headers'))->toBe('protected headers(): array')
        ->and(streamingSignature(VercelDataProtocol::class, 'maskedErrorParts'))->toBe('protected maskedErrorParts(): Generator')
        ->and(streamingSignature(VercelDataProtocol::class, '__construct'))->toBe('public __construct(?string $messageId = NULL): mixed')
        ->and(streamingSignature(StreamProtocol::class, 'encode'))->toBe('protected encode(array $part): string')
        ->and(streamingProperty(StreamProtocol::class, 'errored'))->toBe('protected bool')
        ->and(streamingProperty(StreamProtocol::class, 'exception'))->toBe('protected ?Throwable')
        ->and(streamingProperty(StreamProtocol::class, 'started'))->toBe('protected bool')
        ->and(streamingProperty(StreamableAgentResponse::class, 'invocationId'))->toBe('public string');
});

it('pins the tool events the relay listens to', function (string $class, array $parameters) {
    $constructor = new ReflectionMethod($class, '__construct');

    expect(array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), $constructor->getParameters()))->toBe($parameters);

    foreach ($parameters as $parameter) {
        expect((new ReflectionProperty($class, $parameter))->isPublic())->toBeTrue();
    }
})->with([
    'InvokingTool' => [InvokingTool::class, ['invocationId', 'toolInvocationId', 'agent', 'tool', 'arguments']],
    'ToolInvoked' => [ToolInvoked::class, ['invocationId', 'toolInvocationId', 'agent', 'tool', 'arguments', 'result', 'time']],
    'ToolFailed' => [ToolFailed::class, ['invocationId', 'toolInvocationId', 'agent', 'tool', 'arguments', 'exception', 'time']],
    'StartingStep' => [StartingStep::class, ['invocationId', 'stepNumber', 'agent', 'provider', 'model', 'isFinalStep', 'messages', 'options']],
]);

it('pins the tool request, the pending step and the agent input', function () {
    expect(streamingSignature(Request::class, 'toolInvocationId'))->toBe('public toolInvocationId(): ?string')
        ->and((new Request([], 'call_1', 'inv_1'))->toolInvocationId())->toBe('inv_1')
        ->and(streamingSignature(PendingStep::class, 'withMessages'))->toBe('public withMessages(iterable $messages): self')
        ->and(streamingProperty(PendingStep::class, 'messages'))->toBe('public array')
        ->and(streamingSignature(AgentInput::class, 'message'))->toBe('public message(): ?Laravel\Ai\Messages\UserMessage')
        ->and(streamingSignature(AgentInput::class, 'decisions'))->toBe('public decisions(): ?Laravel\Ai\Approvals\Decisions')
        ->and(MessageRole::User->value)->toBe('user');
});

it('pins the conversation store members the transcript reads', function () {
    expect(streamingSignature(PaginatesConversations::class, 'paginateConversationMessages'))
        ->toBe("public paginateConversationMessages(string \$conversationId, int \$perPage = 15, string \$cursorName = 'cursor', Illuminate\\Pagination\\Cursor|string|null \$cursor = NULL): Illuminate\\Contracts\\Pagination\\CursorPaginator")
        ->and(streamingProperty(StoredMessage::class, 'id'))->toBe('public string')
        ->and(streamingProperty(StoredMessage::class, 'role'))->toBe('public string')
        ->and(streamingProperty(StoredMessage::class, 'content'))->toBe('public string');
});

it('pins the protocol members a confirmation\'s wire relies on', function () {
    expect(streamingProperty(VercelDataProtocol::class, 'messageId'))->toBe('protected ?string')
        ->and(streamingSignature(VercelDataProtocol::class, 'toolResultPart'))->toBe('protected toolResultPart('.ToolResult::class.' $event): array')
        ->and(streamingSignature(VercelDataProtocol::class, 'mapEvent'))->toBe('protected mapEvent(Laravel\Ai\Streaming\Events\StreamEvent $event): ?array')
        ->and(streamingProperty(ToolResultData::class, 'id'))->toBe('public string')
        ->and(streamingProperty(ToolResultData::class, 'name'))->toBe('public string');
});

it('pins the tool result event a resumed call arrives as', function () {
    $constructor = new ReflectionMethod(ToolResult::class, '__construct');

    expect(array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), $constructor->getParameters()))
        ->toBe(['id', 'toolResult', 'successful', 'error', 'timestamp', 'denied', 'preliminary']);

    foreach (['toolResult', 'successful', 'error', 'denied', 'preliminary'] as $property) {
        expect((new ReflectionProperty(ToolResult::class, $property))->isPublic())->toBeTrue();
    }
});

it('pins that an agent input\'s decisions win over its message', function () {
    $input = new class implements AgentInput
    {
        public function message(): ?UserMessage
        {
            return new UserMessage('Words.');
        }

        public function decisions(): ?Decisions
        {
            return Decisions::from(['call_1' => Decision::approve()]);
        }
    };

    $extract = new ReflectionMethod(Promptable::class, 'extractPromptInput');
    [$text, $decisions] = (fn (): array => $this->extractPromptInput($input))->call(new HostToolAgent(User::factory()->make()));

    expect($extract->isPrivate())->toBeTrue()
        ->and($text)->toBe('')
        ->and($decisions)->toBeInstanceOf(Decisions::class)
        ->and($decisions?->get('call_1')?->isApproved())->toBeTrue();
});

it('pins the callbacks that end a turn\'s reservation when its stream ends or fails', function () {
    expect(streamingSignature(StreamableAgentResponse::class, 'then'))->toBe('public then(callable $callback): self')
        ->and(streamingSignature(StreamableAgentResponse::class, 'catch'))->toBe('public catch(callable $callback): self');
});

it('pins the exception a stale confirmation raises when the turn starts', function () {
    expect(is_subclass_of(ApprovalMismatchException::class, AiException::class))->toBeTrue()
        ->and(streamingSignature(ApprovalMismatchException::class, '__construct'))->toBe('public __construct(string $message, Illuminate\Support\Collection $pendingApprovals): mixed');
});

it('pins the conversation id a streamed response carries, under which a new conversation keeps its tables', function () {
    expect(streamingProperty(StreamableAgentResponse::class, 'conversationId'))->toBe('public ?string')
        ->and((new StreamableAgentResponse('run-1', fn (): Generator => yield from []))->withinConversation('conversation-1')->conversationId)->toBe('conversation-1');
});

it('pins the tool calls of a stored message, whose ids the tables a turn showed are kept under', function () {
    expect(streamingSignature(StoredMessage::class, 'toolCalls'))->toBe('public toolCalls(): array')
        ->and((new StoredMessage('m1', 'assistant', '', steps: [['tool_calls' => [['id' => 'call_1', 'name' => 'post-stats']]]]))->toolCalls())
        ->toBe([['id' => 'call_1', 'name' => 'post-stats']]);
});
