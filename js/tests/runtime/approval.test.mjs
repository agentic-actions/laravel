import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { afterEach, describe, mock, test } from 'node:test';
import { AbstractChat, lastAssistantMessageIsCompleteWithApprovalResponses, readUIMessageStream } from 'ai';

/*
 * Confirmations on the client: approvalCard() finds the card a message still waits on, <ApprovalCard> shows it, and
 * the /ai-sdk preset posts the person's answers, by themselves, once each waiting call has one, and never a tool's input.
 */

// The real React and its server renderer; Inertia is stubbed, since importing /react installs its reload.
const require = createRequire(import.meta.url);
const React = require('react');
const { renderToStaticMarkup } = require('react-dom/server');

mock.module('@inertiajs/core', { namedExports: { router: { reload() {}, visit() {}, flushAll() {}, flushByCacheTags() {} }, HttpResponseError: class extends Error {} } });
mock.module('@inertiajs/react', { namedExports: { Link: ({ children }) => children, useHttp: () => ({}) } });

const { actionRows, approvalCard } = await import('../../dist/index.js');
const { ApprovalCard } = await import('../../dist/react.js');
const { actionsChat, actionsTransport } = await import('../../dist/ai-sdk.js');

/** The card of 7.1, as the server builds it. */
const CARD = {
    action: 'delete-post',
    effect: 'destructive',
    label: 'Waiting for your confirmation',
    title: 'Delete this post? This cannot be undone.',
    summary: [
        { label: 'Post', value: 'Launch notes' },
        { label: 'Status', value: 'draft' },
    ],
    confirm: 'Confirm',
    decline: 'Decline',
};

/** A tool part as useChat holds it, in the given state. */
const toolPart = (id, state, extra = {}) => ({ type: 'tool-delete-post', toolCallId: id, state, input: {}, approval: { id }, ...extra });

/** The card part for a call. */
const cardPart = (id, data = CARD) => ({ type: 'data-approval', id: `approval:${id}`, data });

/** The server's stream: a Vercel UI message stream v1 body, one data line per part. */
const sse = (parts) => [...parts.map((part) => `data: ${JSON.stringify(part)}\n\n`), 'data: [DONE]\n\n'].join('');

const words = (id, text) => [
    { type: 'text-start', id },
    { type: 'text-delta', id, delta: text },
    { type: 'text-end', id },
];

/** 7.1: the pause turn, with one waiting call, or two. */
const pause = (ids = ['call_1']) =>
    sse([
        { type: 'start', messageId: 'msg-a1' },
        { type: 'start-step' },
        ...words('t1', 'I can delete it once you confirm.'),
        ...ids.flatMap((id) => [
            { type: 'tool-input-available', toolCallId: id, toolName: 'delete-post', input: {} },
            { type: 'tool-approval-request', toolCallId: id, approvalId: id },
        ]),
        ...ids.map((id) => cardPart(id, { ...CARD, title: `Delete ${id}?` })),
        { type: 'finish-step' },
        { type: 'finish', finishReason: 'tool-calls' },
    ]);

/** 7.3: the resume turn, continuing the answered message; settled unless the server had no messageId. */
const resume = ({ settle = 'available', messageId = 'msg-a1', ids = ['call_1'] } = {}) =>
    sse([
        { type: 'start', messageId },
        { type: 'start-step' },
        ...ids.flatMap((id) =>
            settle === 'denied'
                ? [
                      { type: 'tool-output-denied', toolCallId: id },
                      { type: 'data-action', id: `a:${id}`, data: { action: 'delete-post', label: 'Declined', status: 'declined' } },
                  ]
                : [
                      { type: 'data-action', id: `a:inv-${id}`, data: { action: 'delete-post', label: 'Removing…', status: 'running', effect: 'destructive' } },
                      { type: 'data-action', id: `a:inv-${id}`, data: { action: 'delete-post', label: 'Removed', status: 'done', effect: 'destructive', touches: ['posts'] } },
                      ...(settle === 'available' ? [{ type: 'tool-output-available', toolCallId: id, output: null }] : []),
                  ],
        ),
        { type: 'finish-step' },
        { type: 'start-step' },
        ...words('t2', settle === 'denied' ? 'Okay, I left it.' : 'Deleted it.'),
        { type: 'finish-step' },
        { type: 'finish', finishReason: 'stop' },
    ]);

const STREAM_HEADERS = { 'Content-Type': 'text/event-stream; charset=utf-8' };

/** A fetch that answers each request with the next body, and records the posted JSON. */
function serving(...answers) {
    const calls = [];

    const fetch = async (_url, init) => {
        calls.push(JSON.parse(init.body));

        const answer = answers[calls.length - 1];

        if (answer === undefined) {
            throw new Error(`an unexpected request: ${init.body}`);
        }

        return new Response(answer, { status: 200, headers: STREAM_HEADERS });
    };

    return { fetch, calls };
}

/** A Chat on ai 7's AbstractChat with plain state, as @ai-sdk/react's Chat builds on it. */
class TestChat extends AbstractChat {
    constructor({ messages = [], ...init }) {
        super({
            ...init,
            state: {
                status: 'ready',
                error: undefined,
                messages,
                pushMessage(message) {
                    this.messages = [...this.messages, message];
                },
                popMessage() {
                    this.messages = this.messages.slice(0, -1);
                },
                replaceMessage(index, message) {
                    this.messages = this.messages.map((current, position) => (position === index ? message : current));
                },
                snapshot: (value) => structuredClone(value),
            },
        });
    }
}

/** Wait until the chat has rested for a while: any request it would send by itself has been sent and answered. */
async function idle(chat) {
    for (let tick = 0, quiet = 0; tick < 5000 && quiet < 25; tick++) {
        await new Promise((resolve) => setImmediate(resolve));
        quiet = chat.status === 'ready' || chat.status === 'error' ? quiet + 1 : 0;
    }
}

/** The chat's tool parts, as [call id, state]. */
const toolStates = (chat) => chat.messages.flatMap((message) => message.parts.filter((part) => part.type.startsWith('tool-')).map((part) => [part.toolCallId, part.state]));

afterEach(() => {
    delete globalThis.location;
    delete globalThis.document;
});

describe('approvalCard()', () => {
    test('is null for no message and a message with no parts', () => {
        assert.equal(approvalCard(undefined), null);
        assert.equal(approvalCard({ parts: [] }), null);
        assert.equal(approvalCard({ parts: [{ type: 'text', text: 'Hi' }, { type: 'step-start' }] }), null);
    });

    test('gives the card and the approval id for a tool part that still asks', () => {
        const message = { parts: [{ type: 'step-start' }, { type: 'text', text: 'Once you confirm.' }, toolPart('call_7', 'approval-requested', { approval: { id: 'appr-7' } }), cardPart('call_7')] };

        assert.deepEqual(approvalCard(message), { ...CARD, id: 'appr-7' });
    });

    test('is null once the tool part was answered or settled', () => {
        for (const state of ['approval-responded', 'output-available', 'output-denied', 'output-error']) {
            assert.equal(approvalCard({ parts: [toolPart('call_7', state, { approval: { id: 'call_7', approved: true } }), cardPart('call_7')] }), null, state);
        }
    });

    test('shows the first of two waiting calls, then the next once the first is answered', () => {
        const first = { ...CARD, title: 'Delete the first?' };
        const second = { ...CARD, title: 'Delete the second?' };
        const waiting = { parts: [toolPart('call_1', 'approval-requested'), toolPart('call_2', 'approval-requested'), cardPart('call_1', first), cardPart('call_2', second)] };

        assert.equal(approvalCard(waiting)?.title, 'Delete the first?');
        assert.equal(approvalCard(waiting)?.id, 'call_1');

        waiting.parts[0] = toolPart('call_1', 'approval-responded', { approval: { id: 'call_1', approved: true } });

        assert.deepEqual(approvalCard(waiting), { ...second, id: 'call_2' });
    });

    test('is null for a card with no waiting tool part, and skips a waiting tool part with no card, such as a host tool', () => {
        assert.equal(approvalCard({ parts: [cardPart('call_1')] }), null);
        assert.equal(approvalCard({ parts: [toolPart('call_1', 'approval-requested')] }), null);
        assert.equal(approvalCard({ parts: [toolPart('call_1', 'approval-requested'), cardPart('call_2')] }), null);

        const hostFirst = { parts: [{ type: 'tool-publish', toolCallId: 'call_1', state: 'approval-requested', input: {}, approval: { id: 'call_1' } }, toolPart('call_2', 'approval-requested'), cardPart('call_2')] };

        assert.equal(approvalCard(hostFirst)?.id, 'call_2');
    });

    test('is null for a card whose text is not all strings, so a person never confirms half a card', () => {
        const malformed = [
            null,
            'Delete it?',
            [],
            { ...CARD, title: 42 },
            { ...CARD, summary: 'Post: Launch notes' },
            { ...CARD, summary: [{ label: 'Post', value: 7 }] },
            { ...CARD, summary: [null] },
            { ...CARD, summary: [{ label: 'Post', value: { html: '<b>x</b>' } }] },
            { ...CARD, confirm: undefined },
            { ...CARD, decline: null },
            { ...CARD, label: ['Waiting'] },
            { ...CARD, action: 1 },
        ];

        for (const data of malformed) {
            assert.equal(approvalCard({ parts: [toolPart('call_1', 'approval-requested'), cardPart('call_1', data)] }), null, JSON.stringify(data));
        }
    });

    test('never matches a tool part whose ids are not strings', () => {
        assert.equal(approvalCard({ parts: [toolPart('call_1', 'approval-requested', { approval: { id: 7 } }), cardPart('call_1')] }), null);
        assert.equal(approvalCard({ parts: [toolPart('call_1', 'approval-requested', { approval: null }), cardPart('call_1')] }), null);
        assert.equal(approvalCard({ parts: [toolPart(undefined, 'approval-requested', { approval: { id: 'x' } }), { type: 'data-approval', id: 'approval:undefined', data: CARD }] }), null);
    });
});

describe('<ApprovalCard>', () => {
    const markup = (approval) => renderToStaticMarkup(React.createElement(ApprovalCard, { approval, onAnswer: () => {} }));

    test("renders the server's sentence and rows, the values inside <bdi>, and its two labels on the buttons", () => {
        assert.equal(
            markup({ ...CARD, id: 'call_1' }),
            '<section aria-label="Waiting for your confirmation" data-agentic-approval="" data-effect="destructive">'
                + '<p><bdi>Delete this post? This cannot be undone.</bdi></p>'
                + '<dl><div><dt>Post</dt><dd><bdi>Launch notes</bdi></dd></div><div><dt>Status</dt><dd><bdi>draft</bdi></dd></div></dl>'
                + '<button type="button">Confirm</button><button type="button">Decline</button>'
                + '</section>',
        );
    });

    test('renders no <dl> without rows, and Arabic as it came', () => {
        const html = markup({ ...CARD, effect: 'external', label: 'بانتظار تأكيدك', title: 'هل تريد المتابعة؟', summary: [], confirm: 'تأكيد', decline: 'رفض', id: 'call_1' });

        assert.ok(!html.includes('<dl'));
        assert.equal(
            html,
            '<section aria-label="بانتظار تأكيدك" data-agentic-approval="" data-effect="external"><p><bdi>هل تريد المتابعة؟</bdi></p>'
                + '<button type="button">تأكيد</button><button type="button">رفض</button></section>',
        );
    });

    test('renders every string as text, never as markup', () => {
        const hostile = '<img src=x onerror=alert(1)>';
        const html = markup({ ...CARD, label: `"${hostile}`, title: hostile, summary: [{ label: hostile, value: hostile }], confirm: hostile, decline: hostile, id: 'call_1' });

        assert.ok(!html.includes('<img'));
        assert.match(html, /<bdi>&lt;img src=x onerror=alert\(1\)&gt;<\/bdi>/);
    });

    test('Confirm calls onAnswer(true) and Decline onAnswer(false)', () => {
        const answers = [];
        const element = ApprovalCard({ approval: { ...CARD, id: 'call_1' }, onAnswer: (approved) => answers.push(approved) });
        const buttons = element.props.children.filter((child) => child?.type === 'button');

        assert.deepEqual(buttons.map((button) => button.props.children), ['Confirm', 'Decline']);

        buttons[0].props.onClick();
        buttons[1].props.onClick();

        assert.deepEqual(answers, [true, false]);
    });
});

describe("the transport's answer body", () => {
    /** An assistant message holding every kind of part, one call answered. */
    const answered = {
        id: 'msg-a1',
        role: 'assistant',
        parts: [
            { type: 'step-start' },
            { type: 'text', text: 'I can delete it once you confirm.', state: 'done' },
            toolPart('call_1', 'approval-responded', {
                input: { post: 7, note: 'CANARY-ARG' },
                approval: { id: 'call_1', approved: true, reason: 'Yes, that one.', requestReason: 'CANARY-REASON', descriptor: { x: 1 }, signature: 'sig', isAutomatic: true },
                callProviderMetadata: { openai: { id: 'x' } },
            }),
            toolPart('call_2', 'approval-requested', { input: { post: 8 } }),
            toolPart('call_3', 'output-available', { input: { post: 9 }, output: { secret: true }, approval: { id: 'call_3', approved: true } }),
            { type: 'dynamic-tool', toolName: 'host-tool', toolCallId: 'call_4', state: 'approval-responded', input: { to: 'x' }, approval: { id: 'call_4', approved: false } },
            cardPart('call_1'),
            { type: 'data-action', id: 'a:inv-1', data: { action: 'x', label: 'x', status: 'done' } },
        ],
    };

    const answer = (transport, messages, overrides = {}) =>
        transport.sendMessages({ chatId: 'assistant', messages, trigger: 'submit-message', messageId: 'msg-a1', abortSignal: undefined, body: { ignored: true }, ...overrides });

    test('carries the words and each answered tool part reduced to type, call id, state and approval, and no input', async () => {
        const { fetch, calls } = serving(resume());

        await answer(actionsTransport({ api: '/assistant', fetch, body: () => ({ page: { url: '/teams/acme/posts', component: 'Posts/Index' } }) }), [
            { id: 'm1', role: 'user', parts: [{ type: 'text', text: 'Delete my launch notes' }] },
            answered,
        ]);

        assert.deepEqual(calls[0], {
            page: { url: '/teams/acme/posts', component: 'Posts/Index' },
            ignored: true,
            messages: [
                {
                    id: 'msg-a1',
                    role: 'assistant',
                    parts: [
                        { type: 'text', text: 'I can delete it once you confirm.' },
                        { type: 'tool-delete-post', toolCallId: 'call_1', state: 'approval-responded', approval: { id: 'call_1', approved: true, reason: 'Yes, that one.' } },
                        { type: 'dynamic-tool', toolCallId: 'call_4', state: 'approval-responded', approval: { id: 'call_4', approved: false } },
                    ],
                },
            ],
        });
        assert.ok(!JSON.stringify(calls[0]).includes('CANARY'));
        assert.ok(!('input' in calls[0].messages[0].parts[1]));
    });

    test('lets an assistant messageId through, and still refuses to edit a user message', async () => {
        const { fetch, calls } = serving(resume());
        const transport = actionsTransport({ api: '/assistant', fetch });
        const user = { id: 'm1', role: 'user', parts: [{ type: 'text', text: 'Delete it' }] };

        await answer(transport, [user, answered]);
        await assert.rejects(answer(transport, [user, answered], { messageId: 'm1' }), /Regenerating or editing a message is not supported/);

        assert.equal(calls.length, 1);
    });
});

describe('the confirmation loop, with a Chat over hand-written streams', () => {
    test('the pause shows the card and sends nothing more; Confirm sends one request with the answer, and the resume settles it', async () => {
        const { fetch, calls } = serving(pause(), resume());
        const chat = new TestChat(actionsChat({ api: '/assistant', fetch }));

        await chat.sendMessage({ text: 'Delete my launch notes' });
        await idle(chat);

        assert.equal(calls.length, 1);
        assert.deepEqual(toolStates(chat), [['call_1', 'approval-requested']]);
        assert.equal(chat.lastMessage.parts.find((part) => part.type === 'tool-delete-post').approval.requestReason, undefined);

        const approval = approvalCard(chat.lastMessage);

        assert.deepEqual(approval, { ...CARD, title: 'Delete call_1?', id: 'call_1' });

        await chat.addToolApprovalResponse({ id: approval.id, approved: true });
        await idle(chat);

        assert.equal(calls.length, 2);
        assert.deepEqual(calls[1].messages, [
            {
                id: 'msg-a1',
                role: 'assistant',
                parts: [
                    { type: 'text', text: 'I can delete it once you confirm.' },
                    { type: 'tool-delete-post', toolCallId: 'call_1', state: 'approval-responded', approval: { id: 'call_1', approved: true } },
                ],
            },
        ]);
        assert.equal(chat.messages.length, 2);
        assert.equal(chat.lastMessage.id, 'msg-a1');
        assert.deepEqual(toolStates(chat), [['call_1', 'output-available']]);
        assert.equal(approvalCard(chat.lastMessage), null);
        assert.deepEqual(actionRows(chat.lastMessage).map((row) => [row.label, row.status, row.effect]), [['Removed', 'done', 'destructive']]);
        assert.equal(chat.status, 'ready');
    });

    test('a resume that never settles the part, from a server without a messageId, sends nothing again either', async () => {
        const { fetch, calls } = serving(pause(), resume({ settle: 'none', messageId: 'inv-2' }));
        const chat = new TestChat(actionsChat({ api: '/assistant', fetch }));

        await chat.sendMessage({ text: 'Delete my launch notes' });
        await idle(chat);
        await chat.addToolApprovalResponse({ id: 'call_1', approved: true });
        await idle(chat);

        assert.equal(calls.length, 2);
        assert.equal(chat.lastMessage.parts.find((part) => part.type === 'tool-delete-post').state, 'approval-responded');
        assert.equal(approvalCard(chat.lastMessage), null);
        assert.equal(chat.status, 'ready');
    });

    test('Decline sends the answer, and the resume leaves the part denied with a declined row', async () => {
        const { fetch, calls } = serving(pause(), resume({ settle: 'denied' }));
        const chat = new TestChat(actionsChat({ api: '/assistant', fetch }));

        await chat.sendMessage({ text: 'Delete my launch notes' });
        await idle(chat);
        await chat.addToolApprovalResponse({ id: 'call_1', approved: false, reason: 'Not that one.' });
        await idle(chat);

        assert.equal(calls.length, 2);
        assert.deepEqual(calls[1].messages[0].parts[1].approval, { id: 'call_1', approved: false, reason: 'Not that one.' });
        assert.deepEqual(toolStates(chat), [['call_1', 'output-denied']]);
        assert.deepEqual(actionRows(chat.lastMessage).map((row) => [row.id, row.label, row.status]), [['a:call_1', 'Declined', 'declined']]);
        assert.equal(approvalCard(chat.lastMessage), null);
    });

    test('two waiting calls: one card at a time, and the answers go out together in one request', async () => {
        const { fetch, calls } = serving(pause(['call_1', 'call_2']), resume({ ids: ['call_1', 'call_2'] }));
        const chat = new TestChat(actionsChat({ api: '/assistant', fetch }));

        await chat.sendMessage({ text: 'Delete both' });
        await idle(chat);

        assert.equal(approvalCard(chat.lastMessage).id, 'call_1');

        await chat.addToolApprovalResponse({ id: 'call_1', approved: true });
        await idle(chat);

        assert.equal(calls.length, 1);
        assert.equal(approvalCard(chat.lastMessage).title, 'Delete call_2?');

        await chat.addToolApprovalResponse({ id: 'call_2', approved: false });
        await idle(chat);

        assert.equal(calls.length, 2);
        assert.deepEqual(
            calls[1].messages[0].parts.filter((part) => part.type !== 'text').map((part) => [part.toolCallId, part.approval.approved]),
            [
                ['call_1', true],
                ['call_2', false],
            ],
        );
        assert.equal(approvalCard(chat.lastMessage), null);
    });

    test("a host's own sendAutomaticallyWhen replaces the preset's", async () => {
        const { fetch, calls } = serving(pause());
        const chat = new TestChat(actionsChat({ api: '/assistant', fetch, sendAutomaticallyWhen: () => false }));

        await chat.sendMessage({ text: 'Delete my launch notes' });
        await idle(chat);
        await chat.addToolApprovalResponse({ id: 'call_1', approved: true });
        await idle(chat);

        assert.equal(calls.length, 1);
        assert.equal(actionsChat({ api: '/assistant' }).sendAutomaticallyWhen, lastAssistantMessageIsCompleteWithApprovalResponses);
    });
});

/** The workbench's recorded turns (StreamFixturesTest writes them from a real pause and a real resume). */
const recorded = ['approval-pause.sse', 'approval-resume.sse'].map((name) => new URL(`../fixtures/streams/${name}`, import.meta.url));

describe('the recorded pause and resume', () => {
    /** The parts of a recorded body, as a stream of chunks. */
    const chunks = (file) => {
        const parts = readFileSync(file, 'utf8')
            .split('\n\n')
            .filter((frame) => frame.startsWith('data: ') && frame !== 'data: [DONE]')
            .map((frame) => JSON.parse(frame.slice('data: '.length)));

        return new ReadableStream({
            start(controller) {
                parts.forEach((part) => controller.enqueue(part));
                controller.close();
            },
        });
    };

    /** The last message readUIMessageStream() builds. */
    const read = async (options) => {
        let message;

        for await (const snapshot of readUIMessageStream(options)) {
            message = snapshot;
        }

        return message;
    };

    test('the pause asks with no request reason and an input-less part, and the resume settles the same message after a step-start', async () => {
        const paused = await read({ stream: chunks(recorded[0]) });
        const waiting = paused.parts.find((part) => part.type.startsWith('tool-') && part.state === 'approval-requested');

        assert.ok(waiting !== undefined, 'a tool part waits for approval');
        assert.equal(waiting.approval.requestReason, undefined);
        assert.ok(!('requestReason' in waiting.approval));
        assert.deepEqual(waiting.input, {});
        assert.equal(approvalCard(paused)?.id, waiting.approval.id);

        const answered = {
            ...paused,
            parts: paused.parts.map((part) => (part === waiting ? { ...part, state: 'approval-responded', approval: { ...part.approval, approved: true } } : part)),
        };
        const resumed = await read({ message: answered, stream: chunks(recorded[1]) });
        const settled = resumed.parts.findIndex((part) => part.toolCallId === waiting.toolCallId);
        const lastStep = resumed.parts.findLastIndex((part) => part.type === 'step-start');

        assert.equal(resumed.id, paused.id);
        assert.equal(resumed.parts[settled].state, 'output-available');
        assert.ok(lastStep > settled, 'a step-start part follows the settled part');
        assert.equal(lastAssistantMessageIsCompleteWithApprovalResponses({ messages: [resumed] }), false);
        assert.equal(approvalCard(resumed), null);
    });
});
