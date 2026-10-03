// The /ai-sdk preset against ai 7's own types: ChatInit, the transport and the message's data parts.
import {
    AbstractChat,
    lastAssistantMessageIsCompleteWithApprovalResponses,
    readUIMessageStream,
    type ChatInit,
    type ChatTransport,
    type DataUIPart,
    type DefaultChatTransport,
    type UIMessage,
} from 'ai';
import {
    actionRows,
    approvalCard,
    createActionSync,
    elicitation,
    type ActionDataParts,
    type ActivityRow,
    type ActivityStatus,
    type ApprovalCardData,
    type ElicitationData,
    type ElicitResult,
    type WaitingApproval,
    type WaitingElicitation,
} from '@agentic-actions/client';
import {
    actionsChat,
    actionsTransport,
    answerElicitation,
    refusalMessage,
    turnOutcome,
    type ActionMessage,
    type ActionsChatOptions,
    type TransportOptions,
    type TurnOutcome,
} from '@agentic-actions/client/ai-sdk';

type Equal<A, B> = (<T>() => T extends A ? 1 : 2) extends <T>() => T extends B ? 1 : 2 ? true : false;

function expectTrue<T extends true>(): T | void {}

expectTrue<Equal<ActionMessage, UIMessage<unknown, ActionDataParts>>>();

// The transport is ai's own DefaultChatTransport, so Chat and useChat take it.
const transport: DefaultChatTransport<ActionMessage> = actionsTransport({ api: '/assistant' });
const asTransport: ChatTransport<ActionMessage> = transport;

const sync = createActionSync();

// actionsChat() builds ChatInit<ActionMessage> for new Chat(...), with the host's callbacks typed by it.
const init: ChatInit<ActionMessage> = actionsChat({
    api: '/assistant',
    id: 'assistant',
    messages: [{ id: 'm1', role: 'user', parts: [{ type: 'text', text: 'Hi' }] }],
    body: () => ({ page: { url: '/posts/create', component: 'Posts/Create' } }),
    headers: () => ({ Authorization: 'Bearer abc' }),
    credentials: 'include',
    deadlineMs: 240_000,
    fetch: globalThis.fetch,
    onData: (part) => {
        expectTrue<Equal<typeof part, DataUIPart<ActionDataParts>>>();

        if (part.type === 'data-action') {
            const status: ActivityStatus = part.data.status;
            const touches: string[] | undefined = part.data.touches;
        }

        if (part.type === 'data-approval') {
            expectTrue<Equal<typeof part.data, ApprovalCardData>>();
        }

        if (part.type === 'data-elicitation') {
            expectTrue<Equal<typeof part.data, ElicitationData>>();
        }
    },
    onError: (error: Error) => refusalMessage(error),
    onFinish: (event) => {
        const outcome: TurnOutcome = turnOutcome(event);

        if (outcome === 'interrupted' || outcome === 'failed') {
            sync.flush();
        }
    },
});

// @ts-expect-error api is required
actionsTransport({});

// @ts-expect-error deadlineMs is a number of milliseconds
const badDeadline: TransportOptions = { api: '/assistant', deadlineMs: '240000' };

// @ts-expect-error headers are read at send time, from a function
const badHeaders: TransportOptions = { api: '/assistant', headers: { Authorization: 'Bearer abc' } };

// A host may replace the rule that sends a step's answers, with ai's own helpers or its own.
const ownRule: ActionsChatOptions = { api: '/assistant', sendAutomaticallyWhen: ({ messages }) => messages.length > 1 };
const aiRule: ActionsChatOptions = { api: '/assistant', sendAutomaticallyWhen: lastAssistantMessageIsCompleteWithApprovalResponses };

// @ts-expect-error the rule is a function of the messages
const badAutomatic: ActionsChatOptions = { api: '/assistant', sendAutomaticallyWhen: true };

// An ActionMessage's data-approval parts are typed, and approvalCard() reads the message as useChat holds it.
declare const latest: ActionMessage;
const approval: WaitingApproval | null = approvalCard(latest);

for (const part of latest.parts) {
    if (part.type === 'data-approval') {
        const summary: { label: string; value: string }[] = part.data.summary;
    }
}

// The vanilla reader: ai's own readUIMessageStream, then actionRows().
async function read(stream: ReadableStream<never>): Promise<ActivityRow[]> {
    let rows: ActivityRow[] = [];

    for await (const message of readUIMessageStream<ActionMessage>({ stream })) {
        rows = actionRows(message);
    }

    return rows;
}

const outcome: TurnOutcome = turnOutcome({ message: init.messages![0]!, messages: [], isAbort: false, isDisconnect: false, isError: false });
const sentence: string | null = refusalMessage(undefined);

// 0.5: a waiting form, answered through answerElicitation() on the host's Chat, which extends ai's AbstractChat.
declare class HostChat extends AbstractChat<ActionMessage> {}
declare const chat: HostChat;

const form: WaitingElicitation | null = elicitation(latest);
const answered: Promise<Record<string, string[]> | null> = answerElicitation(chat, { api: '/assistant' }, 'call_1', { action: 'accept', content: { title: 'Launch notes' } });

expectTrue<Equal<Parameters<typeof answerElicitation>[3], ElicitResult>>();

// The narrowest chat it takes: its id, its messages and addToolApprovalResponse().
answerElicitation({ id: 'assistant', messages: [], addToolApprovalResponse: async () => {} }, { api: '/assistant' }, 'call_1', { action: 'cancel' });

// @ts-expect-error the chat must answer approvals
answerElicitation({ id: 'assistant', messages: [] }, { api: '/assistant' }, 'call_1', { action: 'decline' });

// @ts-expect-error an answer is an ElicitResult
answerElicitation(chat, { api: '/assistant' }, 'call_1', { approved: true });

export { aiRule, answered, form, approval, asTransport, badAutomatic, badDeadline, badHeaders, outcome, ownRule, read, sentence };
