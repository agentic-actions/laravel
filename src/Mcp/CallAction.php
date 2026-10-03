<?php

namespace AgenticActions\Mcp;

use AgenticActions\Elicitation\Form;
use Generator;
use Illuminate\Support\Facades\Crypt;
use Laravel\Mcp\Enums\ErrorCode;
use Laravel\Mcp\Enums\MetaKey;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\ToolInvoker;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Throwable;

/**
 * tools/call as laravel/mcp answers it, except for an action that asks: a non-legacy request whose client declares form
 * elicitation gets an InputRequiredResult for a call missing fields a form can hold, and its retry with the answer and
 * the request state runs the action with the person's values, through every check a direct call runs. Every other
 * client and call gets CallTool's answer, the refusal naming the fields included.
 *
 * @upstream The package answers tools/call itself, so an action can ask for input.
 *
 * @internal
 */
final class CallAction extends CallTool
{
    /**
     * The request state's purpose: no other payload the app's key encrypted verifies as one.
     */
    private const PURPOSE = 'agentic-actions.mcp-form';

    /**
     * No form: CallTool's answer. No state, a stale one (expired, or bound to another credential, person, tenant, tool,
     * arguments or form) or no answer under the tool's name: a fresh InputRequiredResult. Decline or cancel: an error
     * result, nothing ran. Accept: a new InputRequiredResult while the action's own rules refuse the answer, else the
     * run, whose result first names the filled fields.
     *
     * @return Generator<JsonRpcResponse>|JsonRpcResponse
     *
     * @throws JsonRpcException -32602 for a request state that does not decrypt to this package's payload, or an answer
     *                          that is not an elicitation result
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        $tool = $context->tools()->first(fn (Tool $tool): bool => $tool->name() === $request->get('name'));
        $form = $tool instanceof McpTool && self::formCapable($request) ? $tool->form($request->toRequest()->all()) : null;

        if (! $tool instanceof McpTool || $form === null) {
            return parent::handle($request, $context);
        }

        $responses = $request->get('inputResponses', []);
        $answer = is_array($responses) ? ($responses[$tool->name()] ?? null) : false;

        if ($request->get('requestState') === null || ! self::verified($request, $form) || $answer === null) {
            return self::inputRequired($request, $tool, $form);
        }

        $content = is_array($answer) ? ($answer['content'] ?? []) : null;

        if (! is_array($answer) || ! in_array($answer['action'] ?? null, ['accept', 'decline', 'cancel'], true) || ! is_array($content) || ($content !== [] && array_is_list($content))) {
            throw new JsonRpcException('Invalid params: the [inputResponses] entry is not an elicitation result.', ErrorCode::INVALID_PARAMS->value, $request->id);
        }

        if ($answer['action'] !== 'accept') {
            $text = (string) trans('agentic-actions::model.'.($answer['action'] === 'decline' ? 'form_declined' : 'form_cancelled'), ['fields' => implode(', ', $form->fields)], $form->context->locale);

            return JsonRpcResponse::result($request->id, ['content' => [['type' => 'text', 'text' => $text]], 'isError' => true]);
        }

        $values = $form->content($content);
        $again = $tool->form($form->merge($request->toRequest()->all(), $values));

        return $again !== null && array_diff(array_keys($again->errors), $form->fields) === []
            ? self::inputRequired($request, $tool, $form, $values, $again->errors)
            : (new ToolInvoker)->invoke($tool->filled($form, $values), $request);
    }

    /**
     * Whether the request is not legacy and its client capabilities hold elicitation as {} or with "form".
     */
    private static function formCapable(JsonRpcRequest $request): bool
    {
        $elicitation = $request->isLegacy() ? null : ($request->meta()[MetaKey::CLIENT_CAPABILITIES->value]['elicitation'] ?? null);

        return is_array($elicitation) && ($elicitation === [] || array_key_exists('form', $elicitation));
    }

    /**
     * resultType input_required: one elicitation/create keyed by the tool's name, holding MCP's standard keys only, and
     * a fresh request state: the purpose, binding() and the expiry, encrypted and authenticated with the app's key.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, list<string>>  $errors
     */
    private static function inputRequired(JsonRpcRequest $request, McpTool $tool, Form $form, array $values = [], array $errors = []): JsonRpcResponse
    {
        $expires = now()->addSeconds(max(1, (int) config('agentic-actions.approvals.ttl', 1800)))->getTimestamp();

        return JsonRpcResponse::result($request->id, [
            'resultType' => 'input_required',
            'inputRequests' => [$tool->name() => ['method' => 'elicitation/create', 'params' => $form->params(false, $values, $errors)]],
            'requestState' => Crypt::encryptString(json_encode(['v' => 1, 'p' => self::PURPOSE, 'b' => self::binding($form), 'e' => $expires], JSON_THROW_ON_ERROR)),
        ]);
    }

    /**
     * Whether the retry's request state binds this form and credential and has not expired.
     *
     * @throws JsonRpcException -32602 when it does not decrypt to this package's payload
     */
    private static function verified(JsonRpcRequest $request, Form $form): bool
    {
        try {
            $state = $request->get('requestState');
            $payload = is_string($state) ? json_decode(Crypt::decryptString($state), true, 4, JSON_THROW_ON_ERROR) : null;
        } catch (Throwable) {
            $payload = null;
        }

        if (! is_array($payload) || ($payload['v'] ?? null) !== 1 || ($payload['p'] ?? null) !== self::PURPOSE || ! is_string($payload['b'] ?? null) || ! is_int($payload['e'] ?? null)) {
            throw new JsonRpcException('Invalid params: the [requestState] does not verify.', ErrorCode::INVALID_PARAMS->value, $request->id);
        }

        return $payload['e'] >= now()->getTimestamp() && hash_equals(self::binding($form), $payload['b']);
    }

    /**
     * SHA-256 of what a state binds: Form::binding() (the action, person, tenant and the arguments' fingerprint) with the
     * credential (a hash of the request's bearer token, whichever guard reads it: Sanctum's, an OAuth access token or
     * the app's own; "-" without one, the person alone), then the requestedSchema.
     */
    private static function binding(Form $form): string
    {
        $bearer = request()->bearerToken();
        $credential = $bearer === null ? '-' : hash('sha256', $bearer);

        return hash('sha256', $form->binding($credential, 'mcp')."\n".json_encode($form->schema, JSON_THROW_ON_ERROR));
    }
}
