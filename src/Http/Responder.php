<?php

namespace AgenticActions\Http;

use AgenticActions\Action;
use AgenticActions\Contracts\RendersOutcomes;
use AgenticActions\Effect;
use AgenticActions\Exposure\Entry;
use AgenticActions\Outcome;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Refusal;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Testing\Fakes\ExceptionHandlerFake;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use LogicException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One responder for JSON callers and browser visits. It renders a success and a refusal without a field itself;
 * everything else is thrown for the app's own exception handler, so the app's dontFlash list, error bags, guest
 * redirect and JSON rule apply exactly. The package never flashes input.
 *
 * @internal
 */
final class Responder implements RendersOutcomes
{
    /**
     * Render an outcome for JSON callers and browser visits.
     *
     * @throws ValidationException for invalid input and a refusal the app's handler renders
     * @throws HttpException for not found and denied, with the fixed sentence in the caller's locale
     */
    public function render(ActionRequest $request, Outcome $outcome): Response
    {
        $entry = $outcome->entry();
        $locale = $outcome->context()->locale;

        return match ($outcome->kind()) {
            OutcomeKind::Ok => $this->completed($request, $outcome),
            OutcomeKind::Refused => $this->refused(
                $request,
                $outcome->refusal() ?? throw new LogicException('A refused outcome carries its refusal.'),
                $entry->errorBag,
                $locale,
            ),
            OutcomeKind::Invalid => throw ValidationException::withMessages($outcome->errors())->errorBag($entry->errorBag),
            OutcomeKind::NotFound => throw new NotFoundHttpException((string) trans('agentic-actions::http.not_found', [], $locale)),
            OutcomeKind::Denied => throw new AccessDeniedHttpException((string) trans('agentic-actions::http.denied', [], $locale)),
            OutcomeKind::Failed => throw $outcome->exception() ?? new LogicException("[{$entry->class}] failed."),
        };
    }

    /**
     * Whether the caller wants JSON: not an Inertia visit, and JSON by the app's own exception handler's rule, so
     * this responder's 200 and 409 and the handler's 422, 404, 403 and 401 always agree on one route.
     *
     * @upstream The handler's JSON rule is read through a bound closure (laravel/framework#61775, declined).
     */
    private static function wantsJson(Request $request): bool
    {
        if ($request->hasHeader('X-Inertia')) {
            return false;
        }

        $handler = app(ExceptionHandler::class);

        // Exceptions::fake() wraps the app's handler and still renders through it.
        if ($handler instanceof ExceptionHandlerFake) {
            $handler = $handler->handler();
        }

        if ($handler instanceof Handler) {
            // shouldReturnJson() is protected: the closure runs bound to the handler.
            return (bool) (fn (Request $request): bool => $this->shouldReturnJson($request, new HttpException(200)))->call($handler, $request);
        }

        return $request->expectsJson();
    }

    /**
     * A success: the projected output as JSON (always for a Read), else a flash and a 303 redirect.
     */
    private function completed(ActionRequest $request, Outcome $outcome): Response
    {
        $entry = $outcome->entry();
        $output = $outcome->output() ?? [];

        if ($entry->effect === Effect::Read || self::wantsJson($request)) {
            return response()->json($output ?: new stdClass, 200);
        }

        $payload = ['name' => $entry->name, 'output' => $output];

        if ($request->hasSession()) {
            if (class_exists(Inertia::class) && $request->hasHeader('X-Inertia')) {
                Inertia::flash('action', $payload);
            } else {
                $request->session()->flash('action', $payload);
            }
        }

        $to = $this->action($request, $entry)->redirectTo($outcome->result(), $outcome->context());

        return redirect($to ?? url()->previous(), 303);
    }

    /**
     * A refusal: JSON with its status, code and details for a JSON caller; a validation error on its field, or on
     * "action", for the app's handler otherwise.
     *
     * @throws ValidationException
     */
    private function refused(Request $request, Refusal $refusal, string $errorBag, string $locale): Response
    {
        if ($refusal->field() !== null || ! self::wantsJson($request)) {
            throw $refusal->toValidationException($errorBag, $locale);
        }

        return response()->json([
            'message' => $refusal->translate($locale),
            'code' => $refusal->key(),
            'details' => $refusal->getDetails() ?: new stdClass,
        ], $refusal->statusCode());
    }

    /**
     * The action whose redirectTo() decides where a visit lands: the route's own controller when it is the action,
     * else a fresh instance. redirectTo() reads only its arguments, so any instance answers the same.
     */
    private function action(ActionRequest $request, Entry $entry): Action
    {
        $route = $request->route();
        $controller = $route instanceof Route ? $route->getController() : null;

        return $controller instanceof Action && $controller::class === $entry->class ? $controller : $entry->action();
    }
}
