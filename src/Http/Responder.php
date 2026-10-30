<?php

namespace AgenticActions\Http;

use AgenticActions\Action;
use AgenticActions\Contracts\RendersOutcomes;
use AgenticActions\Effect;
use AgenticActions\Exposure\Entry;
use AgenticActions\Outcome;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Refusal;
use AgenticActions\Schema\SchemaReader;
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
     * A success: the projected output as JSON (always for a Read, conditional on GET and HEAD), else a flash and a 303
     * redirect.
     */
    private function completed(ActionRequest $request, Outcome $outcome): Response
    {
        $entry = $outcome->entry();
        $output = $outcome->output() ?? [];

        if ($entry->effect === Effect::Read) {
            $response = response()->json($output ?: new stdClass, 200);

            return $request->isMethodCacheable() ? self::conditional($request, $response) : $response;
        }

        if (($rest = RestRegistrar::of($route = $request->route() instanceof Route ? $request->route() : null)) !== null && $route !== null) {
            return self::rest($request, $route, $rest, $outcome);
        }

        if (self::wantsJson($request)) {
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
     * A #[Rest] route's success, always JSON: 201 with its Location for a create, 204 for an action that declares no
     * output, else 200.
     *
     * @param  array{bind: array<string, string>, created: bool, location: string|null, path: string}  $rest
     */
    private static function rest(Request $request, Route $route, array $rest, Outcome $outcome): Response
    {
        $output = $outcome->output() ?? [];
        $status = RestRegistrar::status($outcome->entry(), $rest, app(SchemaReader::class)->output($outcome->entry()->action()));

        if ($status === 204) {
            return response()->noContent();
        }

        $response = response()->json($output ?: new stdClass, $status);

        if ($status === 201 && $rest['location'] !== null && ($location = self::location($route, $rest, $output)) !== null) {
            $response->headers->set('Location', $location);
        }

        return $response;
    }

    /**
     * The Location of a create: the route's group prefix, then $location with each {key} filled from the output's
     * top-level values or, first, the route's parameters, URL-encoded. Null when a key has no value.
     *
     * @param  array{location: string|null, path: string}  $rest
     * @param  array<string, mixed>  $output
     */
    private static function location(Route $route, array $rest, array $output): ?string
    {
        $uri = $route->uri();
        $prefix = rtrim(substr($uri, 0, strlen($uri) - strlen($rest['path'])), '/');
        $values = [...$output, ...$route->originalParameters()];
        $missing = false;

        $path = preg_replace_callback('/\{(\w+)\}/', function (array $match) use ($values, &$missing): string {
            $value = $values[$match[1]] ?? null;

            if (! is_int($value) && ! is_string($value) || $value === '') {
                $missing = true;

                return '';
            }

            return rawurlencode((string) $value);
        }, $prefix.'/'.ltrim((string) $rest['location'], '/'));

        return $missing || $path === null ? null : url($path);
    }

    /**
     * A Read's answer to GET or HEAD: a weak ETag over the body, kept private to the caller and revalidated on every
     * use unless routes.max_age allows a while, varying with the token and the language; 304 without the body when the
     * caller's If-None-Match names it. Built from the projected output, so only after every check has passed.
     */
    private static function conditional(Request $request, Response $response): Response
    {
        $response->setEtag(hash('xxh128', (string) $response->getContent()), true);

        $maxAge = max(0, (int) config('agentic-actions.routes.max_age', 0));
        $response->headers->set('Cache-Control', $maxAge > 0 ? "private, max-age={$maxAge}" : 'private, no-cache');
        // One header line: middleware that appends to Vary, such as Precognition's, reads only the first.
        $response->headers->set('Vary', implode(', ', array_unique([...$response->getVary(), 'Authorization', 'Accept-Language'])));

        $response->isNotModified($request);

        return $response;
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
