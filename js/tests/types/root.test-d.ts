// Compiled with tsc --noEmit: every line either type-checks or carries @ts-expect-error.
import type { UrlMethodPair } from '@inertiajs/core';
import {
    action,
    ActionError,
    ActionFailedError,
    ActionRefusedError,
    ActionValidationError,
    actionParts,
    actionRows,
    approvalCard,
    callAction,
    createActionSync,
    editors,
    elicitation,
    messageSegments,
    newKey,
    notifyTouched,
    onTouched,
    uri,
    xsrfToken,
    type ActionDataParts,
    type ActionDefinition,
    type ActionFeedOptions,
    type ActionPart,
    type ActionRowData,
    type ActionRowsOptions,
    type ActionSync,
    type ActionSyncOptions,
    type ActivityRow,
    type ActivityStatus,
    type ApprovalCardData,
    type CallOptions,
    type ElicitationData,
    type ElicitationField,
    type ElicitationParams,
    type ElicitResult,
    type MessageSegment,
    type SyncRequest,
    type TouchedHandler,
    type ViewData,
    type WaitingApproval,
    type WaitingElicitation,
} from '@agentic-actions/client';

type Equal<A, B> = (<T>() => T extends A ? 1 : 2) extends <T>() => T extends B ? 1 : 2 ? true : false;

function expectTrue<T extends true>(): T | void {}

type NoteInput = { title: string; body?: string | null };
type NoteOutput = { id: number; title: string };

// action() infers ActionDefinition<I, O>.
const createNote = action<NoteInput, NoteOutput>({ name: 'create-note', method: 'post', url: '/actions/create-note', touches: ['notes'] });

expectTrue<Equal<typeof createNote, ActionDefinition<NoteInput, NoteOutput>>>();
expectTrue<Equal<typeof createNote.touches, readonly string[]>>();

// @ts-expect-error a method outside Inertia's five
action({ name: 'x', method: 'head', url: '/', touches: [] });

// @ts-expect-error a GET: callAction() sends the input as the body, which a GET cannot carry
action({ name: 'x', method: 'get', url: '/', touches: [] });

// @ts-expect-error nor does callAction() take a GET definition written by hand
void callAction({ name: 'x', method: 'get', url: '/', touches: [] }, {});

// @ts-expect-error touches are required
action({ name: 'x', method: 'post', url: '/' });

// An ActionDefinition is structurally a UrlMethodPair.
const pair: UrlMethodPair = createNote;
const pairs: UrlMethodPair[] = [createNote, action({ name: 'y', method: 'delete', url: '/y', touches: [] })];

// callAction() requires I and resolves O.
const output: Promise<NoteOutput> = callAction(createNote, { title: 'Hi' });
const withOptions: Promise<NoteOutput> = callAction(createNote, { title: 'Hi', body: null }, {
    idempotencyKey: newKey(),
    headers: { 'X-Trace': '1' },
    credentials: 'include',
    signal: new AbortController().signal,
    fetch: globalThis.fetch,
});

expectTrue<Equal<Awaited<ReturnType<typeof callAction<NoteInput, NoteOutput>>>, NoteOutput>>();

// @ts-expect-error the input is required
callAction(createNote);

// @ts-expect-error a required key is missing
callAction(createNote, { body: 'x' });

// @ts-expect-error a key has the wrong type
callAction(createNote, { title: 1 });

// @ts-expect-error the output is not a string
const wrongOutput: Promise<string> = callAction(createNote, { title: 'Hi' });

// @ts-expect-error credentials take RequestCredentials only
const badOptions: CallOptions = { credentials: 'always' };

// onTouched() returns an unsubscribe function.
const handler: TouchedHandler = (touches, definition) => {
    expectTrue<Equal<typeof touches, readonly string[]>>();
    // Null for touches that carry no definition: a copilot row's, or a host's own touch().
    expectTrue<Equal<typeof definition, ActionDefinition | null>>();
};
const unsubscribe = onTouched(handler);

expectTrue<Equal<typeof unsubscribe, () => void>>();
unsubscribe();

// @ts-expect-error onTouched() does not return a boolean
const subscribed: boolean = onTouched(() => {});

notifyTouched(['notes'], createNote);
notifyTouched(['notes'], null);
notifyTouched(['notes']);

// @ts-expect-error a handler must accept a null definition
const strictHandler: TouchedHandler = (touches: readonly string[], definition: ActionDefinition) => definition.name;

// The error classes narrow with instanceof.
function narrow(error: unknown): void {
    if (error instanceof ActionValidationError) {
        expectTrue<Equal<typeof error.errors, Record<string, string>>>();
        expectTrue<Equal<typeof error.status, number>>();
    }

    if (error instanceof ActionRefusedError) {
        expectTrue<Equal<typeof error.code, string | undefined>>();
        expectTrue<Equal<typeof error.details, Record<string, unknown> | undefined>>();

        // @ts-expect-error field errors belong to ActionValidationError
        error.errors;
    }

    if (error instanceof ActionFailedError) {
        const failed: ActionError = error;
        const status: number = failed.status;
    }

    if (error instanceof ActionError) {
        const message: string = error.message;
    }
}

const filled: string = uri('/teams/{team}/actions/team-note', { team: 'acme' });
const token: string = xsrfToken();

// @ts-expect-error parameter values are strings or numbers
uri('/teams/{team}', { team: true });

// 0.2: rows and the sync coalescer.
const rows: ActivityRow[] = actionRows({ parts: [{ type: 'text' }, { type: 'data-action' }] });
const none: ActivityRow[] = actionRows(undefined);

expectTrue<Equal<ActionDataParts, { action: ActionRowData; approval: ApprovalCardData; elicitation: ElicitationData; view: ViewData }>>();

// 0.4: confirmations. A declined row, the two effects that ask first, and the card a message still waits on.
const declined: ActivityStatus = 'declined';
const removed: ActionRowData = { action: 'delete-post', label: 'Removed', status: 'done', effect: 'destructive' };
const sent: ActionRowData['effect'] = 'external';
const waitingCard: WaitingApproval | null = approvalCard({ parts: [{ type: 'data-approval' }] });
const noCard: WaitingApproval | null = approvalCard(undefined);

expectTrue<Equal<ReturnType<typeof approvalCard>, WaitingApproval | null>>();
expectTrue<Equal<WaitingApproval, ApprovalCardData & { id: string }>>();
expectTrue<Equal<ApprovalCardData['effect'], 'destructive' | 'external'>>();
expectTrue<Equal<ApprovalCardData['summary'], { label: string; value: string }[]>>();

if (waitingCard !== null) {
    const answerId: string = waitingCard.id;
    const title: string = waitingCard.title;
}

// @ts-expect-error a card is for a Destructive or External call only
const writeCard: ApprovalCardData['effect'] = 'write';

// @ts-expect-error a status the server never sends
const approvedStatus: ActivityStatus = 'approved';

const sync: ActionSync = createActionSync({
    apply: async (request) => {
        expectTrue<Equal<typeof request, SyncRequest>>();
        expectTrue<Equal<typeof request.follow, string | null>>();
    },
    when: 'turn',
    how: 'remount',
    blocked: () => false,
    onWaiting: (waiting: boolean) => waiting,
});
const synchronous: ActionSync = createActionSync({ apply: () => undefined });

declare const part: ActionPart;

sync.onPart(part);
sync.touch(['posts']);
sync.flush();
sync.apply();
sync.dispose();

// @ts-expect-error when is 'live' or 'turn'
const badWhen: ActionSyncOptions = { when: 'always' };

// @ts-expect-error blocked is a function, read when the refresh is due
const badBlocked: ActionSyncOptions = { blocked: true };

// 0.3: the change feed.
const feed: ActionFeedOptions = { url: '/teams/acme/actions/_changes', interval: 15_000, fetch: globalThis.fetch };
const polled: ActionSync = createActionSync({ feed: { url: '/actions/_changes' } });
const interval: number | undefined = feed.interval;

// @ts-expect-error the feed needs its url
const noUrl: ActionSyncOptions = { feed: { interval: 1000 } };

// @ts-expect-error the interval is milliseconds, a number
const badInterval: ActionFeedOptions = { url: '/actions/_changes', interval: '15s' };

const key = Symbol('editor');
editors.set(key, true);
const dirty: boolean = editors.dirty();
const unsubscribeEditors: () => void = editors.subscribe(() => {});

// @ts-expect-error an editor's key is a symbol
editors.set('title', true);

// Rows of a settled turn, and a message's content in stream order.
const rowsOptions: ActionRowsOptions = { settled: true };
const settledRows: ActivityRow[] = actionRows({ parts: [] }, rowsOptions);
const segments: MessageSegment[] = messageSegments({ parts: [{ type: 'text' }] }, { settled: false });
const noSegments: MessageSegment[] = messageSegments(undefined);

for (const segment of segments) {
    if (segment.type === 'text') {
        expectTrue<Equal<typeof segment.text, string>>();
    } else {
        expectTrue<Equal<typeof segment.rows, ActivityRow[]>>();
    }
}

// @ts-expect-error settled is a boolean
actionRows(undefined, { settled: 'yes' });

// @ts-expect-error a segment is text or rows
const badSegment: MessageSegment = { type: 'part', text: '' };

/*
 * Every function member is a property, so @typescript-eslint/unbound-method lets a host destructure it or pass it on.
 * A method's parameters are bivariant, so each narrowed assignment below would compile against a method;
 * declarations.test.mjs reads the built declarations for the members that take no parameter.
 */
const { set, dirty: isDirty, subscribe } = editors;
const { emit, end, subscribe: listen } = actionParts;
const { onPart, touch, flush, apply, dispose } = createActionSync({ blocked: isDirty, resumeWhenClean: false });

set(key, false);
subscribe(flush)();
listen({ part: onPart, end: flush })();
emit(part);
end();
touch(['posts']);
apply();
dispose();

// @ts-expect-error a narrower parameter never fits a property
const narrowSet: typeof editors.set = (_key: symbol, _dirty: true) => {};

// @ts-expect-error a narrower parameter never fits a property
const narrowSubscribe: typeof editors.subscribe = (_listener: (() => void) & { tag: 1 }) => () => {};

// @ts-expect-error a narrower parameter never fits a property
const narrowEmit: typeof actionParts.emit = (_part: ActionPart & { tag: 1 }) => {};

// @ts-expect-error a narrower parameter never fits a property
const narrowOnPart: ActionSync['onPart'] = (_part: ActionPart & { tag: 1 }) => {};

// @ts-expect-error a narrower parameter never fits a property
const narrowTouch: ActionSync['touch'] = (_keys: readonly 'posts'[]) => {};

// @ts-expect-error resumeWhenClean is a boolean
const badResume: ActionSyncOptions = { resumeWhenClean: 'no' };

export {
    approvedStatus,
    declined,
    noCard,
    removed,
    sent,
    writeCard,
    badBlocked,
    badOptions,
    badResume,
    badSegment,
    badWhen,
    dirty,
    filled,
    narrow,
    narrowEmit,
    narrowOnPart,
    narrowSet,
    narrowSubscribe,
    narrowTouch,
    noSegments,
    none,
    output,
    pair,
    pairs,
    rows,
    settledRows,
    strictHandler,
    subscribed,
    synchronous,
    token,
    unsubscribeEditors,
    withOptions,
    wrongOutput,
};

// 0.5: forms. The standard's shapes, and the form a message still waits on.
const waitingForm: WaitingElicitation | null = elicitation({ parts: [{ type: 'data-elicitation' }] });
const noForm: WaitingElicitation | null = elicitation(undefined);

expectTrue<Equal<ReturnType<typeof elicitation>, WaitingElicitation | null>>();
expectTrue<Equal<WaitingElicitation, ElicitationData & { id: string }>>();
expectTrue<Equal<ElicitationData['labels'], { source: string; submit: string; decline: string; cancel: string }>>();
expectTrue<Equal<ElicitResult['action'], 'accept' | 'decline' | 'cancel'>>();

if (waitingForm !== null) {
    const answerId: string = waitingForm.id;
    const message: string = waitingForm.params.message;
    const fields: Record<string, ElicitationField> = waitingForm.params.requestedSchema.properties;
}

const params: ElicitationParams = {
    mode: 'form',
    message: 'A few details for your post.',
    requestedSchema: {
        type: 'object',
        properties: {
            title: { type: 'string', title: 'Title', minLength: 1, maxLength: 120, default: 'Launch notes' },
            body: { type: 'string', title: 'Body', 'x-agentic-actions': { widget: 'textarea' } },
            status: { type: 'string', oneOf: [{ const: 'draft', title: 'Draft' }] },
            legacy: { type: 'string', enum: ['a', 'b'], enumNames: ['A', 'B'] },
            count: { type: 'integer', minimum: 1, maximum: 10 },
            share: { type: 'boolean', default: true },
            tags: { type: 'array', items: { anyOf: [{ const: 'news', title: 'News' }] }, minItems: 1, default: ['news'] },
            at: { type: 'string', format: 'date-time' },
        },
        required: ['title'],
    },
};
const accepted: ElicitResult = { action: 'accept', content: { title: 'Launch notes', count: 3, share: false, tags: [] } };
const declinedForm: ElicitResult = { action: 'decline' };

// @ts-expect-error a format the standard does not list
const badFormat: ElicitationField = { type: 'string', format: 'uuid' };

// @ts-expect-error a form holds no object field
const badType: ElicitationField = { type: 'object' };

// @ts-expect-error the widget hint has one value
const badWidget: ElicitationField = { type: 'string', 'x-agentic-actions': { widget: 'tel' } };

// @ts-expect-error an answer is accept, decline or cancel
const badAction: ElicitResult = { action: 'approve' };

// @ts-expect-error content values are strings, numbers, booleans or lists of strings
const badContent: ElicitResult = { action: 'accept', content: { owner: { id: 5 } } };
