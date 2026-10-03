import assert from 'node:assert/strict';
import { afterEach, describe, test } from 'node:test';
import {
    action,
    ActionError,
    ActionFailedError,
    ActionRefusedError,
    ActionValidationError,
    callAction,
    newKey,
    onTouched,
    uri,
    xsrfToken,
} from '../../dist/index.js';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

const createNote = action({ name: 'create-note', method: 'post', url: '/actions/create-note', touches: ['notes'] });

/** A fetch that records each call and answers with the given status and body. */
function stubFetch(status = 200, body = '{"id":1,"title":"Hi"}', contentType = 'application/json') {
    const calls = [];

    const fetch = async (url, init) => {
        calls.push({ url, init });

        return new Response(status === 204 ? null : body, { status, headers: { 'Content-Type': contentType } });
    };

    return { fetch, calls };
}

/** Run a page in Node: a location and a document with the given cookie. */
function page(cookie = 'XSRF-TOKEN=token%3D%3D; laravel_session=abc') {
    globalThis.location = { href: 'https://app.test/notes', origin: 'https://app.test' };
    globalThis.document = { cookie };
}

afterEach(() => {
    delete globalThis.location;
    delete globalThis.document;
});

describe('callAction() requests', () => {
    test('sends Accept, X-Requested-With, a fresh Idempotency-Key and a JSON body', async () => {
        const { fetch, calls } = stubFetch();

        await callAction(createNote, { title: 'Hi', tags: ['a'] }, { fetch });
        await callAction(createNote, { title: 'Hi' }, { fetch });

        const [first, second] = calls;

        assert.equal(first.url, '/actions/create-note');
        assert.equal(first.init.method, 'POST');
        assert.equal(first.init.credentials, 'same-origin');
        assert.equal(first.init.body, '{"title":"Hi","tags":["a"]}');
        assert.equal(first.init.headers.Accept, 'application/json');
        assert.equal(first.init.headers['X-Requested-With'], 'XMLHttpRequest');
        assert.equal(first.init.headers['Content-Type'], 'application/json');
        assert.match(first.init.headers['Idempotency-Key'], UUID);
        assert.match(second.init.headers['Idempotency-Key'], UUID);
        assert.notEqual(first.init.headers['Idempotency-Key'], second.init.headers['Idempotency-Key']);
    });

    test('reuses a given idempotency key, and lets headers and the signal through', async () => {
        const { fetch, calls } = stubFetch();
        const signal = new AbortController().signal;

        await callAction(createNote, { title: 'Hi' }, { fetch, idempotencyKey: 'retry-1', headers: { 'X-Trace': '7', Accept: 'application/vnd.test+json' }, signal });

        assert.equal(calls[0].init.headers['Idempotency-Key'], 'retry-1');
        assert.equal(calls[0].init.headers['X-Trace'], '7');
        assert.equal(calls[0].init.headers.Accept, 'application/vnd.test+json');
        assert.equal(calls[0].init.signal, signal);
    });

    test('hands fetch a request the platform accepts, for each method it takes, with JSON or a file', async () => {
        const requests = [];
        const fetch = async (url, init) => {
            requests.push(new Request(new URL(url, 'https://app.test'), init));

            return new Response('{}', { status: 200, headers: { 'Content-Type': 'application/json' } });
        };

        for (const method of ['post', 'put', 'patch', 'delete']) {
            await callAction(action({ name: 'x', method, url: '/x', touches: [] }), { title: 'Hi' }, { fetch });
            await callAction(action({ name: 'x', method, url: '/x', touches: [] }), { photo: new Blob(['a']) }, { fetch });
        }

        assert.deepEqual(
            requests.map((request) => request.method),
            ['POST', 'POST', 'PUT', 'PUT', 'PATCH', 'PATCH', 'DELETE', 'DELETE'],
        );
        assert.equal(await requests[0].text(), '{"title":"Hi"}');
    });

    test('refuses a GET or HEAD definition before calling fetch: the input travels as the body, which neither carries', async () => {
        const { fetch, calls } = stubFetch();

        for (const method of ['get', 'GET', 'head']) {
            await assert.rejects(callAction(action({ name: 'read-note', method, url: '/read', touches: [] }), {}, { fetch }), {
                message: `callAction() cannot call [read-note] over ${method.toUpperCase()}: it sends the input as the request body, which a ${method.toUpperCase()} request cannot carry.`,
            });
        }

        // The runtime's own fetch never sees it either, so the error is this one, not the platform's.
        await assert.rejects(callAction(action({ name: 'read-note', method: 'get', url: 'http://127.0.0.1:1/read', touches: [] }), {}), {
            message: 'callAction() cannot call [read-note] over GET: it sends the input as the request body, which a GET request cannot carry.',
        });

        assert.equal(calls.length, 0);
    });
});

describe('the XSRF header', () => {
    test('goes to a same-origin URL', async () => {
        page();
        const { fetch, calls } = stubFetch();

        await callAction(createNote, { title: 'Hi' }, { fetch });
        await callAction(action({ name: 'x', method: 'post', url: 'https://app.test/x', touches: [] }), {}, { fetch });

        assert.equal(calls[0].init.headers['X-XSRF-TOKEN'], 'token==');
        assert.equal(calls[1].init.headers['X-XSRF-TOKEN'], 'token==');
    });

    test('never goes to another origin, unless credentials: include was chosen', async () => {
        page();
        const { fetch, calls } = stubFetch();
        const remote = action({ name: 'x', method: 'post', url: 'https://api.other.test/actions/x', touches: [] });

        await callAction(remote, {}, { fetch });
        await callAction(remote, {}, { fetch, credentials: 'same-origin' });
        await callAction(remote, {}, { fetch, credentials: 'include' });

        assert.equal(calls[0].init.headers['X-XSRF-TOKEN'], undefined);
        assert.equal(calls[1].init.headers['X-XSRF-TOKEN'], undefined);
        assert.equal(calls[2].init.headers['X-XSRF-TOKEN'], 'token==');
        assert.equal(calls[2].init.credentials, 'include');
    });

    test('is not sent when the cookie is empty or missing', async () => {
        const { fetch, calls } = stubFetch();

        page('XSRF-TOKEN=; other=1');
        await callAction(createNote, { title: 'Hi' }, { fetch });

        page('other=1');
        await callAction(createNote, { title: 'Hi' }, { fetch });

        assert.equal(calls[0].init.headers['X-XSRF-TOKEN'], undefined);
        assert.equal(calls[1].init.headers['X-XSRF-TOKEN'], undefined);
    });

    test('is not sent where there is no document', async () => {
        globalThis.location = { href: 'https://app.test/notes', origin: 'https://app.test' };
        const { fetch, calls } = stubFetch();

        await callAction(createNote, { title: 'Hi' }, { fetch, credentials: 'include' });

        assert.equal(xsrfToken(), '');
        assert.equal(calls[0].init.headers['X-XSRF-TOKEN'], undefined);
    });

    test('xsrfToken() reads and decodes the cookie', () => {
        page('a=1; XSRF-TOKEN=eyJpdiI6%3D%3D; b=2');

        assert.equal(xsrfToken(), 'eyJpdiI6==');
    });
});

describe('a body with a file', () => {
    test('becomes FormData in bracket notation, with no Content-Type', async () => {
        const { fetch, calls } = stubFetch();
        const avatar = new Blob(['png'], { type: 'image/png' });

        await callAction(
            createNote,
            { title: 'Hi', tags: ['a', 'b'], author: { name: 'Sam', avatar }, draft: true, pinned: false, excerpt: null, skipped: undefined, count: 3 },
            { fetch },
        );

        const body = calls[0].init.body;

        assert.ok(body instanceof FormData);
        assert.equal(calls[0].init.headers['Content-Type'], undefined);
        assert.deepEqual(
            [...body.entries()].map(([key, value]) => [key, typeof value === 'string' ? value : 'blob']),
            [
                ['title', 'Hi'],
                ['tags[0]', 'a'],
                ['tags[1]', 'b'],
                ['author[name]', 'Sam'],
                ['author[avatar]', 'blob'],
                ['draft', '1'],
                ['pinned', '0'],
                ['excerpt', ''],
                ['count', '3'],
            ],
        );
        assert.equal(await body.get('author[avatar]').text(), 'png');
    });

    test('a file in a list counts too', async () => {
        const { fetch, calls } = stubFetch();

        await callAction(createNote, { files: [new Blob(['x'])] }, { fetch });

        assert.ok(calls[0].init.body instanceof FormData);
    });
});

describe('callAction() answers', () => {
    test('resolves the output and calls touched handlers once', async () => {
        const { fetch } = stubFetch(200, '{"id":7,"title":"Hi"}');
        const seen = [];
        const unsubscribe = onTouched((touches, definition) => seen.push([touches, definition.name]));

        try {
            assert.deepEqual(await callAction(createNote, { title: 'Hi' }, { fetch }), { id: 7, title: 'Hi' });
            assert.deepEqual(seen, [[['notes'], 'create-note']]);
        } finally {
            unsubscribe();
        }
    });

    test('resolves {} for an empty body', async () => {
        assert.deepEqual(await callAction(createNote, { title: 'Hi' }, stubFetch(200, '')), {});
        assert.deepEqual(await callAction(createNote, { title: 'Hi' }, stubFetch(204)), {});
    });

    test('throws ActionValidationError with the first message per key', async () => {
        const body = JSON.stringify({ message: 'The title field is required. (and 1 more error)', errors: { title: ['The title field is required.', 'Second.'], body: ['Too long.'] } });

        await assert.rejects(callAction(createNote, {}, stubFetch(422, body)), (error) => {
            assert.ok(error instanceof ActionValidationError);
            assert.ok(error instanceof ActionError);
            assert.equal(error.status, 422);
            assert.equal(error.message, 'The title field is required. (and 1 more error)');
            assert.deepEqual(error.errors, { title: 'The title field is required.', body: 'Too long.' });

            return true;
        });
    });

    test('throws ActionRefusedError with the status, code and details', async () => {
        const body = JSON.stringify({ message: 'You already have a post with that title.', code: 'duplicate', details: { reason: 'x' } });

        await assert.rejects(callAction(createNote, { title: 'Hi' }, stubFetch(409, body)), (error) => {
            assert.ok(error instanceof ActionRefusedError);
            assert.equal(error.status, 409);
            assert.equal(error.message, 'You already have a post with that title.');
            assert.equal(error.code, 'duplicate');
            assert.deepEqual(error.details, { reason: 'x' });

            return true;
        });

        await assert.rejects(callAction(createNote, { title: 'Hi' }, stubFetch(404, '{"message":"Not found."}')), (error) => {
            assert.ok(error instanceof ActionRefusedError);
            assert.equal(error.status, 404);
            assert.equal(error.code, undefined);
            assert.equal(error.details, undefined);

            return true;
        });

        await assert.rejects(callAction(createNote, { title: 'Hi' }, stubFetch(422, '{"message":"No errors object."}')), ActionRefusedError);
    });

    test('throws ActionFailedError for a 5xx or a body that is not JSON', async () => {
        await assert.rejects(callAction(createNote, { title: 'Hi' }, stubFetch(500, '<!doctype html><title>Server Error</title>', 'text/html')), (error) => {
            assert.ok(error instanceof ActionFailedError);
            assert.equal(error.status, 500);
            assert.equal(error.message, '');

            return true;
        });

        await assert.rejects(callAction(createNote, { title: 'Hi' }, stubFetch(503, '{"message":"Down for maintenance."}')), (error) => {
            assert.ok(error instanceof ActionFailedError);
            assert.equal(error.message, 'Down for maintenance.');

            return true;
        });

        await assert.rejects(callAction(createNote, { title: 'Hi' }, stubFetch(419, '<html>Page Expired</html>', 'text/html')), ActionFailedError);
        await assert.rejects(callAction(createNote, { title: 'Hi' }, stubFetch(200, '<html>login</html>', 'text/html')), (error) => error instanceof ActionFailedError && error.status === 200);
    });

    test('calls no touched handler when the call fails', async () => {
        let calls = 0;
        const unsubscribe = onTouched(() => calls++);

        try {
            await assert.rejects(callAction(createNote, { title: 'Hi' }, stubFetch(409, '{"message":"No."}')));
            await assert.rejects(callAction(createNote, { title: 'Hi' }, stubFetch(500, 'oops', 'text/plain')));
            assert.equal(calls, 0);
        } finally {
            unsubscribe();
        }
    });

    test("lets fetch's own network error through", async () => {
        const fetch = async () => {
            throw new TypeError('fetch failed');
        };

        await assert.rejects(callAction(createNote, { title: 'Hi' }, { fetch }), TypeError);
    });
});

describe('uri() and newKey()', () => {
    test('uri() fills and encodes parameters', () => {
        assert.equal(uri('/teams/{team}/actions/team-note', { team: 'acme & co' }), '/teams/acme%20%26%20co/actions/team-note');
        assert.equal(uri('/posts/{post}/archive/{mode?}', { post: 7, mode: 'soft' }), '/posts/7/archive/soft');
        assert.equal(uri('/posts/{post}/archive/{mode?}', { post: 7 }), '/posts/7/archive');
        assert.throws(() => uri('/teams/{team}', {}), /Missing route parameter \[team\]/);
    });

    test('newKey() makes v4 UUIDs, with or without crypto.randomUUID()', () => {
        assert.match(newKey(), UUID);

        const descriptor = Object.getOwnPropertyDescriptor(globalThis, 'crypto');
        const { getRandomValues } = globalThis.crypto;

        Object.defineProperty(globalThis, 'crypto', { value: { getRandomValues: getRandomValues.bind(globalThis.crypto) }, configurable: true });

        try {
            const keys = new Set(Array.from({ length: 50 }, () => newKey()));

            assert.equal(keys.size, 50);

            for (const key of keys) {
                assert.match(key, UUID);
            }
        } finally {
            Object.defineProperty(globalThis, 'crypto', descriptor);
        }
    });
});
