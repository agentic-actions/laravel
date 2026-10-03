<?php

namespace AgenticActions\Queue;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use AgenticActions\Exceptions\ReadActionWrote;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Pipeline\Door;
use AgenticActions\Pipeline\ModelDriven;
use AgenticActions\Runner;
use AgenticActions\Security\ReadGuard;
use AgenticActions\Security\TokenCheck;
use AgenticActions\Surface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LogicException;

/**
 * One queued call of an action, run in the worker as its caller. Action::dispatch() builds it through capture(); a job
 * pushed by hand with `new RunAction(...)` skips capture(), and with it the Read rule and the caller's limits.
 *
 * The class name and the constructor's properties are a stored format: jobs wait in queues across deploys, so a later
 * release only adds trailing nullable properties.
 *
 * @internal
 */
final class RunAction implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A caller or tenant hard-deleted before the run: the job is dropped, as Laravel drops any job whose model is gone.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * The queued runs executing in this process, innermost last. Static, so never part of the stored format.
     *
     * @var list<self>
     */
    private static array $running = [];

    /**
     * Create the job. Not readonly: SerializesModels restores the properties after construction. Encrypted on the
     * queue (ShouldBeEncrypted), since the input sits in the payload, failed_jobs and any dashboard.
     *
     * @param  class-string<Action>  $action
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $fixed  the caller's fixed input (route parameters, host presets), overlaid in the worker as on HTTP
     * @param  (Model&Authenticatable)|null  $actor
     * @param  list<string>|null  $grants  what the caller's credential granted; null means no token limits
     * @param  string  $origin  the Surface value of the caller
     */
    public function __construct(
        public string $action,
        public array $input,
        public array $fixed,
        public ?Model $actor,
        public ?Model $tenant,
        public string $locale,
        public ?array $grants,
        public string $origin,
        public ?string $idempotencyKey,
    ) {}

    /**
     * Capture a call as a job: refuse a non-Read queued by a Read's own code, and keep the caller's limits.
     *
     * @param  class-string<Action>  $class
     * @param  array<string, mixed>  $input
     *
     * @throws ReadActionWrote
     * @throws LogicException when the actor is not an Eloquent model
     */
    public static function capture(string $class, array $input, ActionContext $context): self
    {
        if (app(ReadGuard::class)->active() && ClassExposure::of($class)->effect !== Effect::Read) {
            throw ReadActionWrote::queueing($class);
        }

        if ($context->actor !== null && ! $context->actor instanceof Model) {
            throw new LogicException("[{$class}] cannot be queued for an actor that is not an Eloquent model: the worker restores the actor by its key.");
        }

        return new self(
            $class, $input, $context->fixed, $context->actor, $context->tenant, $context->locale,
            app(TokenCheck::class)->capture($context), self::origin($context)->value, $context->idempotencyKey,
        );
    }

    /**
     * Run the full pipeline in the worker, as the caller. A crash fails the job after ActionFailed; a refusal ends it.
     */
    public function handle(Runner $runner): void
    {
        // SerializesModels restores without global scopes, so a soft-deleted person or tenant comes back: drop the job,
        // as HTTP and MCP refuse them.
        foreach ([$this->actor, $this->tenant] as $model) {
            if ($model !== null && method_exists($model, 'trashed') && $model->trashed()) {
                $this->delete();

                return;
            }
        }

        $context = ActionContext::queued(
            Surface::from($this->origin), $this->actor, $this->tenant, $this->locale, $this->fixed, $this->grants, $this->idempotencyKey,
        );

        self::$running[] = $this;

        try {
            $runner->run(ClassExposure::of($this->action), $this->input, $context, Door::InProcess, rethrow: true);
        } finally {
            array_pop(self::$running);
        }
    }

    /**
     * The innermost queued run executing now, or null. While it runs it stands in for the request that queued it: a
     * call its own code makes reads its model origin (ModelDriven) with any context, and its grants (TokenCheck) with
     * any context but console() and system(), which skip the token check here as they do everywhere.
     */
    public static function running(): ?self
    {
        return self::$running === [] ? null : self::$running[array_key_last(self::$running)];
    }

    /**
     * The surface a model queued this run from (Mcp or Agent), or null when no model did.
     */
    public function modelOrigin(): ?Surface
    {
        $origin = Surface::tryFrom($this->origin);

        return $origin?->isModelDriven() === true ? $origin : null;
    }

    /**
     * The caller's surface as the pipeline reads it: a call inside laravel/mcp or a laravel/ai tool is model-driven,
     * and so is a call inside a queued run a model queued, whatever context its code built.
     */
    private static function origin(ActionContext $context): Surface
    {
        $queuedBy = self::running()?->modelOrigin();

        return match (true) {
            $context->surface === Surface::Queue => $context->origin ?? Surface::Queue,
            app()->bound('mcp.request') => Surface::Mcp,
            $queuedBy !== null => $queuedBy,
            ! $context->surface->isModelDriven() && ModelDriven::detect($context) => Surface::Agent,
            default => $context->surface,
        };
    }
}
