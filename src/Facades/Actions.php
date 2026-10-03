<?php

namespace AgenticActions\Facades;

use AgenticActions\ActionsManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void routes(?bool $tenant = null)
 * @method static list<\AgenticActions\Ai\ActionTool> tools(\AgenticActions\ActionContext $context, list<string> $toolsets, \Laravel\Ai\Contracts\Agent|null $agent = null)
 * @method static \AgenticActions\Streaming\ConversationSlot conversation(\Laravel\Ai\Contracts\Agent|string $agent, \Illuminate\Database\Eloquent\Model $participant, \Illuminate\Database\Eloquent\Model|null $tenant = null)
 * @method static \AgenticActions\Outcome attempt(string $action, array<string, mixed> $input, \AgenticActions\ActionContext $context)
 * @method static array{version: int, actions: array<string, array<string, mixed>>, agents: array<string, list<string>>} exposure()
 * @method static void translateUsing(\Closure|null $translator)
 * @method static void membershipUsing(\Closure|null $membership)
 * @method static void scopeUsing(\Closure|null $scope)
 * @method static void refuse(class-string<\Throwable> $exception, \Closure $map)
 * @method static \AgenticActions\Testing\ActionsFake fake(array<class-string<\AgenticActions\Action>, array<string, mixed>|\AgenticActions\Refusal|\Closure> $responses = [])
 * @method static void assertToolset(string $toolset, list<string> $names)
 * @method static void assertAgentTools(\Laravel\Ai\Contracts\Agent $agent)
 *
 * @see ActionsManager
 *
 * @api
 */
final class Actions extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return ActionsManager::class;
    }
}
