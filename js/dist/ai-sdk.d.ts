import { DefaultChatTransport, type AbstractChat, type ChatInit, type ChatOnFinishCallback, type UIMessage } from 'ai';
import { type ActionDataParts, type ElicitResult } from '@agentic-actions/client';
export type ActionMessage = UIMessage<unknown, ActionDataParts>;
export type TransportOptions = {
    api: string;
    /** Read at send time and merged into every request body. The page goes here. */
    body?: () => Record<string, unknown>;
    /** Merged over the defaults, for example an Authorization header for a token client. */
    headers?: () => Record<string, string>;
    /** 'same-origin' unless set; 'include' for a Sanctum SPA on another origin. */
    credentials?: RequestCredentials;
    /** No default: the turn runs as long as the server lets it. A deadline ends the display with isError, not isAbort. */
    deadlineMs?: number;
    /** A fetch implementation, for tests and non-browser runtimes. */
    fetch?: typeof fetch;
};
export type ActionsChatOptions = TransportOptions & Pick<ChatInit<ActionMessage>, 'id' | 'messages' | 'onData' | 'onError' | 'onFinish' | 'sendAutomaticallyWhen'>;
export type TurnOutcome = 'complete' | 'stopped' | 'interrupted' | 'failed';
/**
 * The newest message only, plus the body. The server reads the last message and keeps the history itself. Of that
 * message it sends the words, and each answered confirmation as its id, approved and reason, or a form's answer as its
 * id, approved and the person's ElicitResult: never a tool's input.
 */
export declare function actionsTransport(options: TransportOptions): DefaultChatTransport<ActionMessage>;
/**
 * Answer a waiting form. An accept is checked first by a Precognition request to the chat's endpoint: errors come
 * back for the form to show, and nothing is sent. Then the answer goes out with the step's other answers, as a
 * confirmation's does. Resolves to the errors by field, or null once the answer is on its way.
 */
export declare function answerElicitation(chat: Pick<AbstractChat<ActionMessage>, 'id' | 'messages' | 'addToolApprovalResponse'>, options: TransportOptions, id: string, result: ElicitResult): Promise<Record<string, string[]> | null>;
/**
 * ChatInit for new Chat(...): the transport, the initial messages, and the data-action bus for useActionSync. The
 * answers to a step's confirmations go out by themselves, together, once each has one.
 */
export declare function actionsChat(options: ActionsChatOptions): ChatInit<ActionMessage>;
/** How a turn ended: useChat reports a cut stream as ready with no finishReason. */
export declare function turnOutcome(event: Parameters<ChatOnFinishCallback<ActionMessage>>[0]): TurnOutcome;
/** The server's own sentence from a 409, 422 or 503: useChat puts the response body in error.message. */
export declare function refusalMessage(error: Error | undefined): string | null;
