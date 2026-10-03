import { DefaultChatTransport, isToolUIPart, lastAssistantMessageIsCompleteWithApprovalResponses, } from 'ai';
import { actionParts, isActionPart, sameOriginUrl, xsrfToken } from '@agentic-actions/client';
/**
 * The person's answers to waiting forms, by the chat's id and the approval id, until the transport sends them: a page
 * may hold two chats, and a provider may reuse a call id.
 */
const answers = new Map();
/**
 * The newest message only, plus the body. The server reads the last message and keeps the history itself. Of that
 * message it sends the words, and each answered confirmation as its id, approved and reason, or a form's answer as its
 * id, approved and the person's ElicitResult: never a tool's input.
 */
export function actionsTransport(options) {
    return new DefaultChatTransport({
        api: options.api,
        credentials: options.credentials ?? 'same-origin',
        headers: () => requestHeaders(options),
        body: () => options.body?.() ?? {},
        fetch: deadlineFetch(options),
        prepareSendMessagesRequest: ({ id, messages, body, trigger, messageId }) => {
            // Chat has already cut its own list by now; the server's history stays whole, and a reload shows it again.
            if (trigger === 'regenerate-message' || (messageId !== undefined && messages.some((message) => message.id === messageId && message.role === 'user'))) {
                throw new Error('Regenerating or editing a message is not supported: the server keeps the history.');
            }
            // An answer is sent once, and never attached to a later part.
            return {
                body: requestBody(messages, body, (approval) => {
                    const answer = answers.get(answerKey(id, approval));
                    answers.delete(answerKey(id, approval));
                    return answer;
                }),
            };
        },
    });
}
/**
 * Answer a waiting form. An accept is checked first by a Precognition request to the chat's endpoint: errors come
 * back for the form to show, and nothing is sent. Then the answer goes out with the step's other answers, as a
 * confirmation's does. Resolves to the errors by field, or null once the answer is on its way.
 */
export async function answerElicitation(chat, options, id, result) {
    const answer = result.action === 'accept' ? { action: 'accept', content: result.content ?? {} } : { action: result.action };
    if (answer.action === 'accept') {
        // The body the transport will send, with this call answered in place; any status but 422 goes on to that send.
        const last = chat.messages.at(-1);
        const answered = last && {
            ...last,
            parts: last.parts.map((part) => (isToolUIPart(part) && part.state === 'approval-requested' && part.approval.id === id ? { ...part, state: 'approval-responded', approval: { id, approved: true } } : part)),
        };
        const headers = new Headers({ 'Content-Type': 'application/json', ...requestHeaders(options) });
        headers.set('Precognition', 'true');
        const response = await (options.fetch ?? fetch)(options.api, {
            method: 'POST',
            credentials: options.credentials ?? 'same-origin',
            headers,
            body: JSON.stringify(requestBody(answered ? [answered] : [], options.body?.() ?? {}, (approval) => (approval === id ? answer : answers.get(answerKey(chat.id, approval))))),
        }).catch(() => null);
        const errors = response?.status === 422 ? (await response.json().catch(() => null))?.errors : null;
        if (typeof errors === 'object' && errors !== null && Object.keys(errors).length > 0) {
            return Object.fromEntries(Object.entries(errors).map(([field, messages]) => [field, [messages].flat().map(String)]));
        }
    }
    answers.set(answerKey(chat.id, id), answer);
    await chat.addToolApprovalResponse({ id, approved: answer.action === 'accept' });
    return null;
}
/**
 * ChatInit for new Chat(...): the transport, the initial messages, and the data-action bus for useActionSync. The
 * answers to a step's confirmations go out by themselves, together, once each has one.
 */
export function actionsChat(options) {
    return {
        id: options.id,
        messages: options.messages,
        onError: options.onError,
        sendAutomaticallyWhen: options.sendAutomaticallyWhen ?? lastAssistantMessageIsCompleteWithApprovalResponses,
        transport: actionsTransport(options),
        onData: (part) => {
            if (isActionPart(part)) {
                actionParts.emit(part);
            }
            options.onData?.(part);
        },
        onFinish: (event) => {
            actionParts.end();
            options.onFinish?.(event);
        },
    };
}
/** How a turn ended: useChat reports a cut stream as ready with no finishReason. */
export function turnOutcome(event) {
    if (event.isAbort) {
        return 'stopped';
    }
    if (event.isDisconnect || (!event.isError && event.finishReason === undefined)) {
        return 'interrupted';
    }
    return event.isError ? 'failed' : 'complete';
}
/** The server's own sentence from a 409, 422 or 503: useChat puts the response body in error.message. */
export function refusalMessage(error) {
    try {
        const parsed = JSON.parse(error?.message ?? '');
        return typeof parsed?.message === 'string' && parsed.message !== '' ? parsed.message : null;
    }
    catch {
        return null;
    }
}
/**
 * A send's body: of the newest message, its words and each answered tool part as its type, call id, state and approval,
 * with the stored answer of a form, if any. The Precognition check and the transport both build it here.
 */
function requestBody(messages, body, answer) {
    const last = messages.at(-1);
    const parts = (last?.parts ?? []).flatMap((part) => {
        if (part.type === 'text') {
            return [{ type: 'text', text: part.text }];
        }
        if (!isToolUIPart(part) || part.state !== 'approval-responded') {
            return [];
        }
        const elicitation = answer(part.approval.id);
        const approval = { id: part.approval.id, approved: part.approval.approved, reason: elicitation === undefined ? part.approval.reason : undefined };
        return [{ type: part.type, toolCallId: part.toolCallId, state: part.state, approval, ...(elicitation === undefined ? {} : { elicitation }) }];
    });
    return { ...body, messages: last === undefined ? [] : [{ id: last.id, role: last.role, parts }] };
}
/** Where an answer waits: the chat's id and the approval id, apart. */
function answerKey(chat, approval) {
    return `${chat}\u0000${approval}`;
}
/** Accept asks Laravel for JSON errors; the XSRF header goes to this page's origin only, unless the app chose 'include'. */
function requestHeaders(options) {
    const token = xsrfToken();
    const trusted = sameOriginUrl(options.api) !== null || options.credentials === 'include';
    return {
        Accept: 'application/json, text/event-stream',
        'X-Requested-With': 'XMLHttpRequest',
        ...(token !== '' && trusted ? { 'X-XSRF-TOKEN': token } : {}),
        ...options.headers?.(),
    };
}
/**
 * The deadline, when one is set, and a guard: a 2xx answer that is not an event stream (a login page, a JSON reply)
 * becomes a 503 with the same body, so the chat shows an error instead of an empty reply.
 */
function deadlineFetch(options) {
    return async (input, init = {}) => {
        const deadline = options.deadlineMs === undefined ? null : AbortSignal.timeout(options.deadlineMs);
        const signal = deadline === null ? init.signal : init.signal ? AbortSignal.any([init.signal, deadline]) : deadline;
        const response = await (options.fetch ?? fetch)(input, { ...init, signal });
        const type = (response.headers.get('Content-Type') ?? '').split(';')[0]?.trim().toLowerCase();
        return response.ok && type !== 'text/event-stream'
            ? new Response(response.body, { status: 503, headers: { 'Content-Type': 'application/json' } })
            : response;
    };
}
