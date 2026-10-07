<?php

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Ai\ActionTool;
use AgenticActions\Effect;
use AgenticActions\Facades\Actions;
use AgenticActions\Schema\AdvertisedSchema;
use AgenticActions\Support\Packages;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\AiManager;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Attributes\CacheToolDefinitions;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\ResolvesPendingApprovals;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Gateway\ParentInvocation;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Concerns\HasTextGateway;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Storage\StoredMessage;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\McpTool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Tests\Fixtures\Ai\AnthropicRequests;
use Tests\Fixtures\Approvals\ScriptedGateway;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * Every laravel/ai symbol the AI module touches, pinned by reflection, so an upstream change fails here first.
 */

beforeEach(function () {
    $this->skipUnlessAi();
});

/**
 * A method's parameters and return type, as "name(Type $param, …): Return", with a return of the declaring class written
 * as "self", as PHP 8.4 prints it and PHP 8.5 does not.
 */
function aiSignature(string $class, string $method): string
{
    $reflection = new ReflectionMethod($class, $method);

    $parameters = array_map(
        fn (ReflectionParameter $parameter): string => trim(($parameter->getType() ?? '').' $'.$parameter->getName()),
        $reflection->getParameters(),
    );

    $return = (string) ($reflection->getReturnType() ?? 'mixed');

    if ($return === $reflection->getDeclaringClass()->getName()) {
        $return = 'self';
    }

    return $method.'('.implode(', ', $parameters).'): '.$return;
}

it('pins the Tool contract to description(), handle(Request) and schema(JsonSchema)', function () {
    $methods = array_map(fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass(Tool::class))->getMethods());

    sort($methods);

    expect($methods)->toBe(['description', 'handle', 'schema'])
        ->and(aiSignature(Tool::class, 'description'))->toBe('description(): Stringable|string')
        ->and(aiSignature(Tool::class, 'handle'))->toBe('handle('.Request::class.' $request): Stringable|string')
        ->and(aiSignature(Tool::class, 'schema'))->toBe('schema('.JsonSchema::class.' $schema): array');
});

it('pins the tool Request\'s call id and arguments', function () {
    $request = new Request(['title' => 'Hi'], 'call_1', 'inv_1');

    expect(aiSignature(Request::class, 'toolCallId'))->toBe('toolCallId(): ?string')
        ->and((new ReflectionMethod(Request::class, 'all'))->isPublic())->toBeTrue()
        ->and($request->toolCallId())->toBe('call_1')
        ->and($request->all())->toBe(['title' => 'Hi']);
});

it('pins the static helpers the package calls', function (string $class, string $method) {
    $reflection = new ReflectionMethod($class, $method);

    expect($reflection->isPublic() && $reflection->isStatic())->toBeTrue();
})->with([
    'ToolNameResolver::resolve()' => [ToolNameResolver::class, 'resolve'],
    'ParentInvocation::current()' => [ParentInvocation::class, 'current'],
    'ParentInvocation::within()' => [ParentInvocation::class, 'within'],
    'McpTool::supports()' => [McpTool::class, 'supports'],
    'McpServerTool::supports()' => [McpServerTool::class, 'supports'],
    'Promptable::fake()' => [Promptable::class, 'fake'],
]);

it('pins HasTools::tools() and Promptable::withTools()', function () {
    expect(aiSignature(HasTools::class, 'tools'))->toBe('tools(): iterable')
        ->and((new ReflectionMethod(Promptable::class, 'withTools'))->isPublic())->toBeTrue()
        ->and(aiSignature(Promptable::class, 'withTools'))->toBe('withTools(Closure|Traversable|array $tools): static');
});

it('pins the ToolCall constructor the tests pass', function () {
    $call = new ToolCall('call_1', 'create-note', ['title' => 'Hi']);

    expect([$call->id, $call->name, $call->arguments])->toBe(['call_1', 'create-note', ['title' => 'Hi']]);
});

it('pins the classes the tool audit mirrors', function () {
    $toolsProperty = new ReflectionProperty(ToolSearch::class, 'tools');

    expect(aiSignature(AgentTool::class, '__construct'))->toBe('__construct('.Agent::class.' $agent): mixed')
        ->and(is_subclass_of(AgentTool::class, Tool::class))->toBeTrue()
        ->and(aiSignature(CanActAsTool::class, 'name'))->toBe('name(): string')
        ->and($toolsProperty->isPublic())->toBeTrue()
        ->and(is_subclass_of(McpTool::class, Tool::class) && is_subclass_of(McpServerTool::class, Tool::class))->toBeTrue()
        ->and(McpTool::supports(new stdClass))->toBeFalse()
        ->and(McpServerTool::supports(new stdClass))->toBeFalse();
});

it('closes the advertised node exactly as laravel/ai closes a tool schema', function () {
    $action = new class extends Action
    {
        protected ?Effect $effect = Effect::Write;

        public function schema(JsonSchema $schema): array
        {
            return [
                'title' => $schema->string()->max(20)->required(),
                'author' => $schema->object([
                    'name' => $schema->string()->required(),
                    'links' => $schema->array()->items($schema->string()),
                ])->required(),
                'tags' => $schema->array()->items($schema->object([
                    'label' => $schema->string()->required(),
                    'weight' => $schema->integer(),
                ])),
            ];
        }
    };

    $context = ActionContext::agent(null);
    $types = app(AdvertisedSchema::class)->types($action, new JsonSchemaTypeFactory, $context);

    expect(app(AdvertisedSchema::class)->node($action, $context))->toBe((new ObjectSchema($types))->toSchema());
});

describe('tool search', function () {
    it('pins the ToolSearch constructor InteractsWithActions calls with the deferred action tools', function () {
        expect(aiSignature(ToolSearch::class, '__construct'))->toBe('__construct(array $tools, ?string $strategy): mixed');
    });

    it('pins what Packages::toolSearch() reads: a provider without tool search runs a group\'s tools from laravel/ai 1.1 on', function () {
        Auth::shouldUse('web');
        config(['ai.conversations.generate_title' => false]);
        $user = User::factory()->create();
        (new ScriptedGateway([['create-note', ['title' => 'Found it', 'body' => 'x']]]))->install('ollama');

        try {
            (new AnonymousAgent('Help.', [], [new ToolSearch(Actions::tools(ActionContext::agent($user), ['default']))]))->prompt('Write a note.', provider: 'ollama');
        } catch (LogicException $exception) {
            expect($exception->getMessage())->toBe('Provider [ollama] does not support tool search.');
        }

        expect(Post::query()->where('user_id', $user->id)->exists())->toBe(app(Packages::class)->toolSearch())
            ->and(app(Packages::class)->toolSearch())->toBe(! $this->laravelAiBefore('1.1.0'));
    });

    it('pins where laravel/ai puts a tool-search group and the cache mark in an Anthropic request, which InteractsWithActions orders its tools for', function () {
        Auth::shouldUse('web');
        AnthropicRequests::fake();
        $deferred = Actions::tools(ActionContext::agent(User::factory()->create()), ['default']);
        $loaded = array_pop($deferred);

        (new #[CacheToolDefinitions] class('Help.', [], [new ToolSearch($deferred), $loaded]) extends AnonymousAgent {})->prompt('Count the notes.', provider: 'anthropic');

        expect($deferred)->not->toBe([])
            ->and(AnthropicRequests::tools())->toBe([
                ['tool_search_tool_regex', false, false],
                ...array_map(fn (ActionTool $tool): array => [$tool->name(), true, false], $deferred),
                [$loaded->name(), false, true],
            ]);
    });

    it('pins #[CacheToolDefinitions], which laravel/ai reads from the agent\'s class and actions:check reads too', function () {
        $agent = new #[CacheToolDefinitions] class('Help.', [], []) extends AnonymousAgent {};

        expect(TextGenerationOptions::forAgent($agent)->cacheToolDefinitions)->toBeInstanceOf(CacheToolDefinitions::class)
            ->and(TextGenerationOptions::forAgent(new AnonymousAgent('Help.', [], []))->cacheToolDefinitions)->toBeNull();
    });
});

describe('confirmations', function () {
    it('pins Approvable, which ActionTool implements by hand', function () {
        $methods = array_map(fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass(Approvable::class))->getMethods());

        sort($methods);

        expect($methods)->toBe(['requireApproval', 'shouldRequestApproval', 'withoutApproval'])
            ->and(aiSignature(Approvable::class, 'requireApproval'))->toBe('requireApproval(?string $reason): static')
            ->and(aiSignature(Approvable::class, 'withoutApproval'))->toBe('withoutApproval(): static')
            ->and(aiSignature(Approvable::class, 'shouldRequestApproval'))->toBe('shouldRequestApproval('.Request::class.' $request): ?'.Approval::class);
    });

    it('pins Approval, Decision and Decisions', function () {
        $decisions = Decisions::from(['call_1' => Decision::approve(), 'call_2' => Decision::reject('No.')]);

        expect(aiSignature(Approval::class, 'required'))->toBe('required(?string $reason): self')
            ->and(Approval::required('Ask first.')->reason)->toBe('Ask first.')
            ->and(aiSignature(Decisions::class, 'from'))->toBe('from(array $decisions): self')
            ->and(aiSignature(Decisions::class, 'rejectRemaining'))->toBe('rejectRemaining(?string $result): self')
            ->and(array_keys($decisions->all()))->toBe(['call_1', 'call_2'])
            ->and($decisions->all()['call_1']->isRejected())->toBeFalse()
            ->and($decisions->all()['call_1']->isEdited())->toBeFalse()
            ->and($decisions->all()['call_2']->isRejected())->toBeTrue()
            ->and($decisions->all()['call_2']->result)->toBe('No.');
    });

    it('pins PendingApproval\'s id, tool and arguments', function () {
        $pending = new PendingApproval('call_1', 'delete-post', ['post' => 7], 'Ask first.');

        expect(aiSignature(PendingApproval::class, '__construct'))->toBe('__construct(string $id, string $tool, array $arguments, ?string $reason): mixed')
            ->and([$pending->id, $pending->tool, $pending->arguments, $pending->reason])->toBe(['call_1', 'delete-post', ['post' => 7], 'Ask first.']);
    });

    it('pins the store contracts that read waiting calls and ownership', function () {
        expect(aiSignature(ResolvesPendingApprovals::class, 'pendingApprovalsFor'))->toBe('pendingApprovalsFor(string $conversationId): array')
            ->and(aiSignature(VerifiesConversationOwnership::class, 'conversationBelongsTo'))
            ->toBe('conversationBelongsTo(string $conversationId, ?string $participantType, string|int|null $participantId): bool')
            ->and(aiSignature(Conversation::class, 'participantType'))->toBe('participantType(object $participant): string')
            ->and(aiSignature(Conversation::class, 'participantKey'))->toBe('participantKey(object $participant): string|int')
            ->and((new ReflectionMethod(Conversation::class, 'participantType'))->isStatic())->toBeTrue()
            ->and((new ReflectionMethod(Conversation::class, 'participantKey'))->isStatic())->toBeTrue();
    });

    it('pins Conversational and the RemembersConversations trait\'s conversation and participant', function () {
        expect(interface_exists(Conversational::class))->toBeTrue()
            ->and(trait_exists(RemembersConversations::class))->toBeTrue()
            ->and(aiSignature(RemembersConversations::class, 'currentConversation'))->toBe('currentConversation(): ?string')
            ->and(aiSignature(RemembersConversations::class, 'hasConversationParticipant'))->toBe('hasConversationParticipant(): bool')
            ->and(aiSignature(RemembersConversations::class, 'continue'))->toBe('continue(string $conversationId, ?object $as): static');
    });

    it('pins ToolApprovalRequested, which the package listens for', function () {
        expect(aiSignature(ToolApprovalRequested::class, '__construct'))->toBe('__construct(string $invocationId, '.Agent::class.' $agent, '.Collection::class.' $pendingApprovals, ?string $conversationId, ?object $conversationUser): mixed');
    });

    it('pins a paused turn\'s stored status', function () {
        expect(MessageStatus::Paused->value)->toBe('paused')
            ->and((string) (new ReflectionProperty(StoredMessage::class, 'status'))->getType())->toBe(MessageStatus::class);
    });

    it('pins the provider\'s own gateway, which the tests script', function () {
        expect(aiSignature(HasTextGateway::class, 'useTextGateway'))->toBe('useTextGateway('.StepTextGateway::class.' $gateway): self')
            ->and(aiSignature(AiManager::class, 'textProvider'))->toBe('textProvider(?string $name): '.TextProvider::class);
    });
});
