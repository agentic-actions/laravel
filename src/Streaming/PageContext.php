<?php

namespace AgenticActions\Streaming;

use AgenticActions\ActionContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Step middleware for #[WithPageContext]: adds the page the person has open to the last user message of each step.
 * The page is re-matched on this app's routes; only its route name and page component reach the model, and the
 * block is sent for the step only, never stored. Built once per run.
 *
 * @api
 */
final class PageContext
{
    /**
     * The run's block, once built; '' when there is none.
     */
    private ?string $block = null;

    /**
     * Create the middleware for the agent's context, whose tenant a page must belong to.
     */
    public function __construct(private readonly ActionContext $context) {}

    /**
     * Send the step with the block appended to its last user message.
     *
     * @param  Closure(PendingStep): mixed  $next
     */
    public function handle(PendingStep $step, Closure $next): mixed
    {
        $this->block ??= $this->build(request());

        return $next($this->block === '' ? $step : $step->withMessages(self::appended($step->messages, $this->block)));
    }

    /**
     * The block for the request's "page" input, or '' when it is missing, fails a check, or belongs to another tenant.
     */
    private function build(Request $request): string
    {
        $url = $request->input('page.url');
        $component = $request->input('page.component');

        if (! is_string($url) || ! is_string($component) || strlen($url) > 2048
            || ! str_starts_with($url, '/') || str_starts_with($url, '//')
            || preg_match('#^[A-Za-z0-9/_-]{1,120}$#', $component) !== 1
            || ! self::isPage($component)) {
            return '';
        }

        $path = parse_url($url, PHP_URL_PATH);

        // A URL that does not parse gives no block, never the home route.
        if (! is_string($path) || $path === '') {
            return '';
        }

        try {
            // Re-matched on the request's own host: a path this router does not serve, or no request can be built
            // from (a backslash), is dropped rather than failing the turn.
            $route = Route::getRoutes()->match(Request::create($request->getSchemeAndHttpHost().$path, 'GET'));
        } catch (HttpExceptionInterface|RequestExceptionInterface) {
            return '';
        }

        $name = $route->getName();
        $tenant = $route->originalParameter((string) config('agentic-actions.tenant.parameter'));

        // Tenants resolve by route key, so the raw segment is compared with the context tenant's route key.
        if ($name === null || ($tenant !== null && (string) $tenant !== (string) $this->context->tenant?->getRouteKey())) {
            return '';
        }

        return (string) trans('agentic-actions::model.page', ['route' => $name, 'component' => $component], $this->context->locale);
    }

    /**
     * Whether Inertia's own page finder knows the component, so free text shaped like a name never reaches the model.
     */
    private static function isPage(string $component): bool
    {
        if (! app()->bound('inertia.view-finder')) {
            return false;
        }

        try {
            app('inertia.view-finder')->find($component);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * The messages with the block appended to the last user message, matched by role so a stored user turn counts
     * whatever class it arrives as.
     *
     * @param  array<int, Message>  $messages
     * @return array<int, Message>
     */
    private static function appended(array $messages, string $block): array
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i]->role === MessageRole::User) {
                $messages[$i] = new UserMessage(
                    trim(($messages[$i]->content ?? '').PHP_EOL.PHP_EOL.$block),
                    $messages[$i] instanceof UserMessage ? $messages[$i]->attachments : [],
                );

                break;
            }
        }

        return $messages;
    }
}
