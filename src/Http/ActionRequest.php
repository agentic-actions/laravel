<?php

namespace AgenticActions\Http;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Contracts\InterceptsActions;
use AgenticActions\Contracts\RendersOutcomes;
use AgenticActions\Effect;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Exposure\Entry;
use AgenticActions\Pipeline\Door;
use AgenticActions\Pipeline\Halt;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Runner;
use AgenticActions\Security\ReadGuard;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The FormRequest bridge: the pipeline placed across FormRequest's hooks. Nothing reads input before exposure,
 * membership and an input-free authorize().
 *
 * @internal
 */
final class ActionRequest extends FormRequest
{
    /**
     * The route-action key that marks a route Actions::routes() generated. It survives route:cache.
     */
    public const GENERATED = 'agentic_generated';

    /**
     * The context, built once per request.
     */
    private ?ActionContext $actionContext = null;

    /**
     * The live entry of the route's controller class.
     */
    private ?Entry $liveEntry = null;

    /**
     * Step 6's rules, as rules() returned them, so validationData() caps the lists they cap.
     *
     * @var array<string, list<mixed>>|null
     */
    private ?array $compiledRules = null;

    /**
     * Wrap FormRequest's resolution (authorize, validate) in the context's tenancy and locale, and in the Read guard
     * for a Read. A guest on a generated route is refused first, before the context reads the tenant, and so is any
     * action but a Read on a method the CSRF check reads as safe.
     *
     * @throws AuthenticationException
     * @throws MethodNotAllowedHttpException
     */
    public function validateResolved(): void
    {
        if ($this->door() === Door::GeneratedRoute && $this->user() === null) {
            throw new AuthenticationException;
        }

        // The CSRF check lets GET, HEAD and OPTIONS through as reading, so a hand-written route on one of them never
        // runs anything but a Read.
        if ($this->isMethodSafe() && $this->entry()->effect !== Effect::Read) {
            throw new MethodNotAllowedHttpException([], Response::$statusTexts[405]);
        }

        $resolve = fn (): mixed => app(Runner::class)->scoped($this->context(), function (): null {
            parent::validateResolved();

            return null;
        });

        if ($this->faked() === null && $this->entry()->effect === Effect::Read && config('agentic-actions.reads.guard')) {
            app(ReadGuard::class)->run($resolve);

            return;
        }

        $resolve();
    }

    /**
     * Steps 1 to 3. Throws the fixed-sentence 404 or 403 itself; validateResolved() has already refused a guest on a
     * generated route.
     */
    public function authorize(): bool
    {
        $this->errorBag = $this->entry()->errorBag;

        if ($this->faked() !== null) {
            return true;
        }

        try {
            app(Runner::class)->admit($this->entry(), $this->context(), $this->door());
        } catch (Halt $halt) {
            $this->halted($halt);
        }

        return true;
    }

    /**
     * Step 6's rules. FormRequest calls it after authorize() passed and before validationData().
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        if ($this->faked() !== null) {
            return [];
        }

        return $this->compiledRules = app(Runner::class)->rules($this->entry(), $this->context());
    }

    /**
     * Step 5 (assemble), called right after rules().
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        /** @var array<string, mixed> $input */
        $input = $this->all();

        if ($this->faked() !== null) {
            return $input;
        }

        $assembled = app(Runner::class)->assemble($this->entry(), $input, $this->context());

        // A list its rules cap reaches the validator with at most one item past the cap, as on every other door.
        return Runner::capped($assembled, $this->compiledRules ?? $this->rules());
    }

    /**
     * Step 6 stopped: fire ActionRefused, as every other door does, then let FormRequest throw its ValidationException
     * for the app's handler. A Precognition request only checks a draft, so it records nothing.
     *
     * @throws ValidationException
     */
    protected function failedValidation(Validator $validator): void
    {
        if (! $this->isAttemptingPrecognition() && $this->faked() === null) {
            app(Runner::class)->recordInvalid($this->entry(), new ValidationException($validator), $this->context());
        }

        parent::failedValidation($validator);
    }

    /**
     * Steps 7 to 10 and the response. Never runs handle() for a Precognition request.
     */
    public function respond(Action $action): Response
    {
        if ($this->isAttemptingPrecognition()) {
            abort(204, headers: ['Precognition-Success' => 'true']);
        }

        /** @var array<string, mixed> $input */
        $input = $this->all();

        $outcome = ($fake = $this->faked()) !== null
            ? $fake->respond($this->entry(), $input, $this->context())
            : app(Runner::class)->complete($this->entry(), $this->validator instanceof LaravelValidator ? Runner::validatedInput($this->validator) : $this->safe(), $this->context(), rethrow: true);

        return app(RendersOutcomes::class)->render($this, $outcome);
    }

    /**
     * The live entry of the route's controller class.
     */
    public function entry(): Entry
    {
        if ($this->liveEntry !== null) {
            return $this->liveEntry;
        }

        $route = $this->route();
        $class = $route instanceof Route ? $route->getControllerClass() : null;

        if ($class === null || ! is_subclass_of($class, Action::class)) {
            throw new LogicException('An ActionRequest serves only a route whose controller is an '.Action::class.'.');
        }

        return $this->liveEntry = ClassExposure::of($class);
    }

    /**
     * ActionContext::fromRequest($this), built once.
     */
    public function context(): ActionContext
    {
        return $this->actionContext ??= ActionContext::fromRequest($this);
    }

    /**
     * GeneratedRoute when the route carries GENERATED, else Route.
     */
    private function door(): Door
    {
        $route = $this->route();

        return $route instanceof Route && $route->getAction(self::GENERATED) === true ? Door::GeneratedRoute : Door::Route;
    }

    /**
     * Turn a stop during admission into what the app's handler renders.
     *
     * @throws \Throwable
     */
    private function halted(Halt $halt): never
    {
        $outcome = $halt->outcome;
        $locale = $this->context()->locale;

        throw match ($outcome->kind()) {
            OutcomeKind::NotFound => new NotFoundHttpException((string) trans('agentic-actions::http.not_found', [], $locale)),
            OutcomeKind::Denied => new AccessDeniedHttpException((string) trans('agentic-actions::http.denied', [], $locale)),
            OutcomeKind::Invalid => ValidationException::withMessages($outcome->errors())->errorBag($this->entry()->errorBag),
            OutcomeKind::Failed => $outcome->exception() ?? new LogicException('The action failed.'),
            default => new HttpResponseException(app(RendersOutcomes::class)->render($this, $outcome)),
        };
    }

    /**
     * The fake bound, if any. Only while the app runs its tests: a fake switches every gate off, so one left bound
     * anywhere else is ignored.
     */
    private function faked(): ?InterceptsActions
    {
        if (! app()->runningUnitTests() || ! app()->bound(InterceptsActions::class)) {
            return null;
        }

        $fake = app(InterceptsActions::class);

        return $fake instanceof InterceptsActions ? $fake : null;
    }
}
