<?php

namespace AgenticActions\Feed;

use AgenticActions\ActionContext;
use AgenticActions\Contracts\ReadsTokenGrants;
use AgenticActions\Effect;
use AgenticActions\Tenancy\Tenants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The change feed's route in every Actions::routes() group: an open page polls it for the keys writes made elsewhere
 * touched.
 *
 * @internal
 */
final class ChangesController
{
    /**
     * The route segment and route name in every Actions::routes() group; no action may take it.
     */
    public const SEGMENT = '_changes';

    /**
     * Answer a poll: 404 unless a signed-in session that may enter the group's tenant asks.
     */
    public function __invoke(Request $request, ChangeFeed $feed, ReadsTokenGrants $reader): JsonResponse
    {
        // The tenant by route key; an unknown one is already a 404 with the same message.
        $context = ActionContext::fromRequest($request);
        $actor = $context->actor;

        abort_if(
            ! config('agentic-actions.feed.enabled')
            || $actor === null
            || $context->guard === null
            || $reader->grants($actor, Auth::guard($context->guard)) !== null   // sessions only; a token never polls
            || ($context->tenant !== null && ! Tenants::member($actor, $context->tenant, Effect::Read)),
            404,
            (string) trans('agentic-actions::http.not_found'),
        );

        $since = $request->json('since');

        return response()->json($feed->since($context, is_int($since) ? $since : null));
    }
}
