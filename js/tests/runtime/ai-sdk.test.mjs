import assert from 'node:assert/strict';
import { afterEach, describe, test } from 'node:test';
import { AbstractChat, DefaultChatTransport, readUIMessageStream } from 'ai';
import { actionParts, actionRows } from '../../dist/index.js';
import { actionsChat, actionsTransport, refusalMessage, turnOutcome } from '../../dist/ai-sdk.js';

/** The server's stream: a Vercel UI message stream v1 body, one data line per part. */
const sse = (parts) => [...parts.map((part) => `data: ${JSON.stringify(part)}\n\n`), 'data: [DONE]\n\n'].join('');

/** 6.4's success turn: a write and a hand-written tool in one step, then the reply and a host part. */
const SUCCESS = sse([
    { type: 'start', messageId: 'id-1' },
    { type: 'start-step' },
    { type: 'data-action', id: 'a:id-2', data: { action: 'create-post', label: 'Saving…', status: 'running', effect: 'write' } },
    { type: 'data-action', id: 'a:id-2', data: { action: 'create-post', label: 'Draft saved', status: 'done', effect: 'write', touches: ['posts'], link: { url: '/posts/42/edit', follow: false } } },
    { type: 'data-action', id: 'a:id-3', data: { action: 'CountDrafts', label: 'Counting your drafts…', status: 'running' } },
    { type: 'data-action', id: 'a:id-3', data: { action: 'CountDrafts', label: 'Counted your drafts', status: 'done' } },
    { type: 'finish-step' },
    { type: 'start-step' },
    { type: 'text-start', id: 'id-4' },
    { type: 'text-delta', id: 'id-4', delta: 'I saved the draft. You have three.' },
    { type: 'text-end', id: 'id-4' },
    { type: 'data-blog-stats', data: { drafts: 3 } },
    { type: 'finish-step' },
    { type: 'finish', finishReason: 'stop' },
]);

/** 6.4's failed turn: the row closed, the host part, the one error part last, and no finish. */
const FAILED = sse([
    { type: 'start', messageId: 'id-1' },
    { type: 'start-step' },
    { type: 'data-action', id: 'a:id-2', data: { action: 'create-post', label: 'Saving…', status: 'running', effect: 'write' } },
    { type: 'data-action', id: 'a:id-2', data: { action: 'create-post', label: 'Draft saved', status: 'done', effect: 'write', touches: ['posts'] } },
    { type: 'finish-step' },
    { type: 'start-step' },
    { type: 'data-blog-stats', data: { drafts: 3 } },
    { type: 'error', errorText: 'The reply stopped before it finished. Anything already saved stays saved.' },
]);

const STREAM_HEADERS = { 'Content-Type': 'text/event-stream; charset=utf-8', 'Cache-Control': 'no-cache, no-transform, private' };

/** A fetch that records each call and answers with a fresh Response each time. */
function stubFetch(answer = () => new Response(SUCCESS, { status: 200, headers: STREAM_HEADERS })) {
    const calls = [];

    const fetch = async (url, init) => {
        calls.push({ url, init, headers: new Headers(init.headers), body: JSON.parse(init.body) });

        return answer(init);
    };

    return { fetch, calls };
}

/** Run a page in Node: a location and a document with the given cookie. */
function page(cookie = 'XSRF-TOKEN=token%3D%3D; laravel_session=abc') {
    globalThis.location = { href: 'https://app.test/posts/create', origin: 'https://app.test' };
    globalThis.document = { cookie };
}

/** The conversation so far, as ai 7's Chat holds it. */
const HISTORY = [
    { id: 'm1', role: 'user', parts: [{ type: 'text', text: 'Hi' }] },
    { id: 'm2', role: 'assistant', parts: [{ type: 'text', text: 'Hello.' }] },
    {
        id: 'm3',
        role: 'user',
        parts: [
            { type: 'file', mediaType: 'image/png', url: 'data:image/png;base64,AAAA' },
            { type: 'text', text: 'Draft a post', state: 'done' },
            { type: 'text', text: 'about tides' },
        ],
    },
];

/** sendMessages() for a new user message, as Chat calls it. */
const send = (transport, overrides = {}) =>
    transport.sendMessages({ chatId: 'assistant', messages: HISTORY, trigger: 'submit-message', messageId: undefined, abortSignal: undefined, ...overrides });

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

afterEach(() => {
    delete globalThis.location;
    delete globalThis.document;
});

describe('actionsTransport() requests', () => {
    test('posts only the newest message, reduced to its text parts, plus body() read at send time', async () => {
        const { fetch, calls } = stubFetch();
        let url = '/posts/create';
        const transport = actionsTransport({ api: '/assistant', fetch, body: () => ({ page: { url, component: 'Posts/Create' }, messages: 'ignored' }) });

        assert.ok(transport instanceof DefaultChatTransport);

        await send(transport);
        url = '/posts/42/edit';
        await send(transport);

        assert.equal(calls[0].url, '/assistant');
        assert.equal(calls[0].init.method, 'POST');
        assert.deepEqual(calls[0].body, {
            page: { url: '/posts/create', component: 'Posts/Create' },
            messages: [{ id: 'm3', role: 'user', parts: [{ type: 'text', text: 'Draft a post' }, { type: 'text', text: 'about tides' }] }],
        });
        assert.equal(calls[1].body.page.url, '/posts/42/edit');
    });

    test('sends JSON-first Accept, X-Requested-With and same-origin credentials, with headers() merged last', async () => {
        const { fetch, calls } = stubFetch();

        await send(actionsTransport({ api: '/assistant', fetch }));
        await send(actionsTransport({ api: '/assistant', fetch, headers: () => ({ Authorization: 'Bearer abc', Accept: 'text/event-stream' }) }));

        assert.equal(calls[0].headers.get('Accept'), 'application/json, text/event-stream');
        assert.equal(calls[0].headers.get('X-Requested-With'), 'XMLHttpRequest');
        assert.equal(calls[0].headers.get('Content-Type'), 'application/json');
        assert.equal(calls[0].init.credentials, 'same-origin');
        assert.equal(calls[1].headers.get('Authorization'), 'Bearer abc');
        assert.equal(calls[1].headers.get('Accept'), 'text/event-stream');
    });

    test('sends the XSRF header to its own origin, read from the cookie at send time', async () => {
        const { fetch, calls } = stubFetch();
        const transport = actionsTransport({ api: '/assistant', fetch });

        page();
        await send(transport);
        page('XSRF-TOKEN=rotated');
        await send(transport);
        await send(actionsTransport({ api: 'https://app.test/assistant', fetch }));

        assert.deepEqual(
            calls.map((call) => call.headers.get('X-XSRF-TOKEN')),
            ['token==', 'rotated', 'rotated'],
        );
    });

    test('never sends the XSRF header to another origin, unless credentials: include was chosen', async () => {
        const { fetch, calls } = stubFetch();

        page();

        for (const api of ['https://api.other.test/assistant', '//evil.test/assistant', '/\\evil.test/assistant', 'https://app.test.evil.test/x', 'http://app.test/assistant']) {
            await send(actionsTransport({ api, fetch }));
        }

        await send(actionsTransport({ api: 'https://api.other.test/assistant', fetch, credentials: 'include' }));

        assert.deepEqual(
            calls.map((call) => call.headers.get('X-XSRF-TOKEN')),
            [null, null, null, null, null, 'token=='],
        );
        assert.equal(calls.at(-1).init.credentials, 'include');
    });

    test('refuses to regenerate or edit a message before any request', async () => {
        const { fetch, calls } = stubFetch();
        const transport = actionsTransport({ api: '/assistant', fetch });
        const refused = /Regenerating or editing a message is not supported: the server keeps the history\./;

        await assert.rejects(send(transport, { trigger: 'regenerate-message', messageId: 'm2' }), refused);
        await assert.rejects(send(transport, { trigger: 'regenerate-message', messageId: undefined }), refused);
        await assert.rejects(send(transport, { messageId: 'm3' }), refused);

        assert.equal(calls.length, 0);
    });
});

describe("actionsTransport()'s fetch", () => {
    test('turns a 2xx answer that is not an event stream into a 503 with the same body', async () => {
        const { fetch } = stubFetch(() => new Response('{"message":"Signed out."}', { status: 200, headers: { 'Content-Type': 'application/json' } }));

        await assert.rejects(send(actionsTransport({ api: '/assistant', fetch })), (error) => {
            assert.equal(error.statusCode, 503);
            assert.equal(error.message, '{"message":"Signed out."}');
            assert.equal(refusalMessage(error), 'Signed out.');

            return true;
        });
    });

    test("lets a 4xx through, so the server's own sentence reaches refusalMessage()", async () => {
        for (const [status, sentence] of [
            [422, 'Write a message of up to 4000 characters.'],
            [409, 'That confirmation is no longer waiting, so nothing ran again.'],
        ]) {
            const { fetch } = stubFetch(() => new Response(JSON.stringify({ message: sentence }), { status, headers: { 'Content-Type': 'application/json' } }));

            await assert.rejects(send(actionsTransport({ api: '/assistant', fetch })), (error) => {
                assert.equal(error.statusCode, status);
                assert.equal(refusalMessage(error), sentence);

                return true;
            });
        }
    });

    test("uses the caller's signal as is when there is no deadline", async () => {
        const { fetch, calls } = stubFetch();
        const controller = new AbortController();

        await send(actionsTransport({ api: '/assistant', fetch }), { abortSignal: controller.signal });

        assert.equal(calls[0].init.signal, controller.signal);
    });

    test('aborts the request at deadlineMs with a timeout, and still on the caller\'s own abort', async () => {
        // Like fetch: rejects with the signal's reason, at once when it is already aborted.
        const hanging = (init) =>
            new Promise((_resolve, reject) => {
                const fail = () => reject(init.signal.reason);

                if (init.signal.aborted) {
                    fail();
                } else {
                    init.signal.addEventListener('abort', fail);
                }
            });
        const { fetch } = stubFetch(hanging);
        const started = Date.now();
        // Node's AbortSignal.timeout() does not keep the process alive; a browser tab needs no such thing.
        const alive = setTimeout(() => {}, 5000);

        try {
            await assert.rejects(send(actionsTransport({ api: '/assistant', fetch, deadlineMs: 50 })), { name: 'TimeoutError' });
        } finally {
            clearTimeout(alive);
        }

        assert.ok(Date.now() - started >= 45);

        const controller = new AbortController();
        const request = send(actionsTransport({ api: '/assistant', fetch, deadlineMs: 60_000 }), { abortSignal: controller.signal });

        controller.abort();

        await assert.rejects(request, { name: 'AbortError' });
    });
});

describe('actionsChat()', () => {
    test('builds ChatInit with the transport, and passes the id, the messages and onError through', () => {
        const onError = () => {};
        const init = actionsChat({ api: '/assistant', id: 'assistant', messages: HISTORY, onError });

        assert.ok(init.transport instanceof DefaultChatTransport);
        assert.equal(init.id, 'assistant');
        assert.equal(init.messages, HISTORY);
        assert.equal(init.onError, onError);
    });

    test('emits only data-action parts to the bus, calls the host\'s onData for every data part, and ends the bus on finish', async () => {
        const { fetch, calls } = stubFetch();
        const bus = [];
        const hostData = [];
        const finished = [];
        const unsubscribe = actionParts.subscribe({ part: (part) => bus.push(['part', part.id, part.data.status]), end: () => bus.push(['end']) });

        try {
            const chat = new TestChat(
                actionsChat({
                    api: '/assistant',
                    fetch,
                    messages: HISTORY.slice(0, 2),
                    onData: (part) => hostData.push(part.type),
                    onFinish: (event) => finished.push(turnOutcome(event)),
                }),
            );

            await chat.sendMessage({ text: 'Draft a post about tides' });

            assert.deepEqual(calls[0].body.messages.map((message) => message.parts), [[{ type: 'text', text: 'Draft a post about tides' }]]);
            assert.deepEqual(bus, [
                ['part', 'a:id-2', 'running'],
                ['part', 'a:id-2', 'done'],
                ['part', 'a:id-3', 'running'],
                ['part', 'a:id-3', 'done'],
                ['end'],
            ]);
            assert.deepEqual(hostData, ['data-action', 'data-action', 'data-action', 'data-action', 'data-blog-stats']);
            assert.deepEqual(finished, ['complete']);
            assert.deepEqual(
                actionRows(chat.lastMessage).map((row) => [row.id, row.status, row.label]),
                [
                    ['a:id-2', 'done', 'Draft saved'],
                    ['a:id-3', 'done', 'Counted your drafts'],
                ],
            );
        } finally {
            unsubscribe();
        }
    });

    test("a failed turn ends the bus too, reads as failed, and shows the server's sentence", async () => {
        const { fetch } = stubFetch(() => new Response(FAILED, { status: 200, headers: STREAM_HEADERS }));
        const ends = [];
        const outcomes = [];
        const unsubscribe = actionParts.subscribe({ end: () => ends.push('end') });

        try {
            const chat = new TestChat(actionsChat({ api: '/assistant', fetch, onFinish: (event) => outcomes.push(turnOutcome(event)) }));

            await chat.sendMessage({ text: 'Draft a post' });

            assert.deepEqual(ends, ['end']);
            assert.deepEqual(outcomes, ['failed']);
            assert.equal(chat.error.message, 'The reply stopped before it finished. Anything already saved stays saved.');
            assert.equal(refusalMessage(chat.error), null);
            assert.deepEqual(actionRows(chat.lastMessage).map((row) => row.status), ['done']);
        } finally {
            unsubscribe();
        }
    });

    test("a regenerate is refused before any request, and the Chat reports the preset's error", async () => {
        const { fetch, calls } = stubFetch();
        const chat = new TestChat(actionsChat({ api: '/assistant', fetch, messages: HISTORY.slice(0, 2) }));

        await chat.regenerate();

        assert.equal(calls.length, 0);
        assert.match(chat.error.message, /Regenerating or editing a message is not supported/);
    });
});

describe('turnOutcome()', () => {
    const event = (fields) => ({ message: HISTORY[1], messages: HISTORY, isAbort: false, isDisconnect: false, isError: false, ...fields });

    test('reads the four endings', () => {
        assert.equal(turnOutcome(event({ finishReason: 'stop' })), 'complete');
        assert.equal(turnOutcome(event({ isAbort: true })), 'stopped');
        assert.equal(turnOutcome(event({ isAbort: true, isError: true })), 'stopped');
        assert.equal(turnOutcome(event({ isDisconnect: true, isError: true })), 'interrupted');
        assert.equal(turnOutcome(event({})), 'interrupted');
        assert.equal(turnOutcome(event({ isError: true })), 'failed');
        assert.equal(turnOutcome(event({ isError: true, finishReason: 'error' })), 'failed');
    });
});

describe('refusalMessage()', () => {
    test("reads a non-empty string message from a JSON body, else null", () => {
        assert.equal(refusalMessage(new Error('{"message":"Too many requests."}')), 'Too many requests.');
        assert.equal(refusalMessage(new Error('{"message":""}')), null);
        assert.equal(refusalMessage(new Error('{"message":42}')), null);
        assert.equal(refusalMessage(new Error('null')), null);
        assert.equal(refusalMessage(new Error('')), null);
        assert.equal(refusalMessage(new Error('<!doctype html><title>Server Error</title>')), null);
        assert.equal(refusalMessage(new Error('Failed to fetch the chat response.')), null);
        assert.equal(refusalMessage(undefined), null);
    });
});

describe('the vanilla reader', () => {
    test("rebuilds the message with ai's own reader, and actionRows() gives one row per id with its last status", async () => {
        page();

        // An event stream passes whatever the case and parameters of its media type.
        const { fetch } = stubFetch(() => new Response(SUCCESS, { status: 200, headers: { 'Content-Type': 'Text/Event-Stream; charset=utf-8' } }));
        const stream = await actionsTransport({ api: '/assistant', fetch }).sendMessages({
            chatId: 'assistant',
            messages: [{ id: 'm1', role: 'user', parts: [{ type: 'text', text: 'Draft a post about tides' }] }],
            trigger: 'submit-message',
            messageId: undefined,
            abortSignal: undefined,
        });
        const seen = [];
        let last;

        for await (const message of readUIMessageStream({ stream })) {
            seen.push(actionRows(message).map((row) => row.status).join(','));
            last = message;
        }

        assert.ok(seen.includes('running'));
        assert.ok(seen.includes('done,running'));
        assert.deepEqual(actionRows(last), [
            { id: 'a:id-2', action: 'create-post', label: 'Draft saved', status: 'done', effect: 'write', link: { url: '/posts/42/edit', follow: false } },
            { id: 'a:id-3', action: 'CountDrafts', label: 'Counted your drafts', status: 'done' },
        ]);
        assert.equal(last.parts.find((part) => part.type === 'text').text, 'I saved the draft. You have three.');
    });
});
