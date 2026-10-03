<?php

namespace AgenticActions\Contracts;

use AgenticActions\Http\ActionRequest;
use AgenticActions\Outcome;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns an outcome into an HTTP response. The HTTP module implements it.
 *
 * @internal
 */
interface RendersOutcomes
{
    /**
     * Turn an outcome into the response for this request. May throw a ValidationException or an HttpException for the
     * app's handler to render.
     */
    public function render(ActionRequest $request, Outcome $outcome): Response;
}
