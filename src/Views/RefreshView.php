<?php

namespace AgenticActions\Views;

use AgenticActions\ActionContext;
use AgenticActions\Contracts\ReadsTokenGrants;
use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Exposure\Entry;
use AgenticActions\Pipeline\Door;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Runner;
use AgenticActions\Streaming\AgenticView;
use AgenticActions\Surface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The refresh route in every Actions::routes() group: the person a table was shown to in the copilot gets its rows
 * again, now, through the pipeline as on the web, from the input and fixed keys it ran with. The snapshot stays as it
 * was, so a reload still shows the table the model described.
 *
 * @internal
 */
final class RefreshView
{
    /**
     * The route segment and route name in every Actions::routes() group; no action may take it.
     */
    public const SEGMENT = '_views';

    /**
     * The route-action key holding the group's tenant argument to Actions::routes().
     */
    public const TENANT = 'agentic_views_tenant';

    /**
     * Answer a refresh with the table's rows now and their time, or the same 404 for anything but the session's own
     * table of an action this group still serves on the web.
     */
    public function __invoke(Request $request, Runner $runner, ReadsTokenGrants $reader): JsonResponse
    {
        $id = $request->route('view');
        $route = $request->route();

        // The ref names this route's snapshot, never an input: an action's own view key keeps its stored value. A tenant
        // that does not exist gets the 404 of one the person is not in, so a name's existence never shows.
        try {
            $context = ActionContext::fromRequest($request)->withoutFixed('view');
        } catch (NotFoundHttpException) {
            return response()->json(['message' => (string) trans('agentic-actions::http.not_found')], 404);
        }
        $view = $this->view($id, $context, $reader);
        $entry = $view === null ? null : $this->entry($view, $route instanceof Route ? $route->getAction(self::TENANT) : null);

        if ($view === null || $entry === null) {
            return self::answer(404, 'agentic-actions::http.not_found', $context);
        }

        // Only a call that ran on no fixed input is refreshed (allowed()), so the stored input is all it takes.
        $outcome = $runner->run($entry, $view->input, $context, Door::GeneratedRoute);

        return match ($outcome->kind()) {
            OutcomeKind::Ok => response()->json(['table' => $outcome->output(), 'at' => now()->format(DATE_ATOM)]),
            OutcomeKind::NotFound, OutcomeKind::Denied => self::answer(404, 'agentic-actions::http.not_found', $context),
            OutcomeKind::Invalid => self::answer(422, 'agentic-actions::http.invalid', $context),
            OutcomeKind::Refused => response()->json(['message' => $outcome->refusal()?->translate($context->locale)], $outcome->status()),
            OutcomeKind::Failed => self::answer(500, 'agentic-actions::activity.failed', $context),   // run() reported it
        };
    }

    /**
     * The snapshot a signed-in session asks for by its id: the actor's, in this context's tenant or none, of a
     * conversation that still exists and belongs to the actor. Null for anything else, a token included.
     */
    private function view(mixed $id, ActionContext $context, ReadsTokenGrants $reader): ?AgenticView
    {
        $actor = $context->actor;

        if (! $actor instanceof Model || $context->guard === null || $reader->grants($actor, Auth::guard($context->guard)) !== null
            || ! is_string($id) || ! Str::isUlid($id) || ! interface_exists(VerifiesConversationOwnership::class)) {
            return null;
        }

        try {
            $view = AgenticView::query()->whereKey(strtolower($id))->where([
                'participant_type' => $actor->getMorphClass(),
                'participant_id' => $actor->getKey(),
                'tenant_type' => $context->tenant?->getMorphClass(),
                'tenant_id' => $context->tenant?->getKey(),
            ])->first();
        } catch (Throwable $exception) {
            AgenticView::reportFailure($exception);

            return null;
        }

        $store = app(ConversationStore::class);

        return $view !== null && $view->fixed === [] && $store instanceof VerifiesConversationOwnership
            && $store->conversationBelongsTo($view->conversation_id, $actor->getMorphClass(), $actor->getKey()) ? $view : null;
    }

    /**
     * The action the snapshot names, while it still shows a table, is open on the web, and is one this group
     * registers: a tenant group only tenant-scoped actions, a group without tenants only the others.
     */
    private function entry(AgenticView $view, mixed $tenant): ?Entry
    {
        $candidate = app(ActionRegistry::class)->find($view->action);
        $entry = $candidate === null ? null : rescue(fn (): Entry => ClassExposure::of($candidate->class), null, false);

        return $entry !== null && $entry->shows() && self::allowed($entry, []) && ($tenant === null || $entry->tenantScoped === $tenant)
            ? $entry
            : null;
    }

    /**
     * Whether a table of this action, shown by a call on this fixed input, can be refreshed: the action is open on the
     * web, the call ran on no fixed input (a preset or a route parameter the refresh route cannot check again), and the
     * class declares no controller middleware of its own, which only its generated route applies.
     *
     * @param  array<string, mixed>  $fixed
     */
    public static function allowed(Entry $entry, array $fixed): bool
    {
        if (! $entry->allows(Surface::Http) || $fixed !== []) {
            return false;
        }

        return rescue(fn (): bool => (new Route(['POST'], self::SEGMENT, ['uses' => $entry->class.'@__invoke']))
            ->setRouter(app('router'))->setContainer(app())->controllerMiddleware() === [], false);
    }

    /**
     * A JSON answer with one of the package's fixed lines.
     */
    private static function answer(int $status, string $key, ActionContext $context): JsonResponse
    {
        return response()->json(['message' => (string) trans($key, [], $context->locale)], $status);
    }
}
