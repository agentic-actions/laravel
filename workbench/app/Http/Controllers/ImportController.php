<?php

namespace Workbench\App\Http\Controllers;

use AgenticActions\ActionContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workbench\App\Actions\ImportPosts;

/**
 * A token client's import, queued: the job keeps the caller's person, team and token abilities, and the worker checks
 * them all again. The route asks for any actions ability (Sanctum's CheckForAnyAbility), so a read-only token gets
 * this far; the worker then refuses the write.
 */
final class ImportController
{
    /**
     * Queue the import and answer at once.
     */
    public function __invoke(Request $request): JsonResponse
    {
        ImportPosts::dispatch($request->only('titles'), ActionContext::fromRequest($request));

        return response()->json(['queued' => true], 202);
    }
}
