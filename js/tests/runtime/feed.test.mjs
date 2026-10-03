import assert from 'node:assert/strict';
import { afterEach, beforeEach, describe, mock, test } from 'node:test';

/** A stub Inertia router that records its calls. */
const calls = [];
const router = {
    flushAll: () => calls.push(['flushAll']),
    flushByCacheTags: (tags) => calls.push(['flushByCacheTags', tags]),
    reload: (options) => calls.push(['reload', options]),
    visit: (href, options) => calls.push(['visit', href, options]),
};

mock.module('@inertiajs/core', { namedExports: { router } });

const { createActionSync, editors } = await import('../../dist/index.js');
const { inertiaApply } = await import('../../dist/inertia.js');

const URL_ = '/teams/acme/actions/_changes';
const MINUTE = 60_000;

/** A stub document: visibility, a cookie, and the listeners the poller adds, which a test can fire. */
function stubDocument() {
    const listeners = new Map();

    globalThis.document = {
        hidden: false,
        cookie: 'XSRF-TOKEN=abc%3D',
        addEventListener: (type, listener) => {
            listeners.set(type, [...(listeners.get(type) ?? []), listener]);
        },
        removeEventListener: (type, listener) => {
            listeners.set(type, (listeners.get(type) ?? []).filter((current) => current !== listener));
        },
    };

    return {
        fire: (type) => (listeners.get(type) ?? []).forEach((listener) => listener()),
        count: () => [...listeners.values()].reduce((sum, list) => sum + list.length, 0),
        hide: (hidden) => {
            globalThis.document.hidden = hidden;
            (listeners.get('visibilitychange') ?? []).forEach((listener) => listener());
        },
    };
}

/**
 * A fake fetch: each call records its request and takes the next answer, a { status, body } or an Error to throw; a
 * body left undefined is not JSON. With no answer left it answers { now: 1000 + n, touches: [] }.
 */
function fakeFetch(answers = []) {
    const requests = [];
    const fetch = async (url, init) => {
        requests.push({ url, init, body: JSON.parse(init.body) });

        const answer = answers.shift() ?? { body: { now: 1000 + requests.length, touches: [] } };

        if (answer instanceof Error) {
            throw answer;
        }

        const status = answer.status ?? 200;

        return {
            status,
            ok: status >= 200 && status < 300,
            json: async () => {
                if (answer.body === undefined) {
                    throw new SyntaxError('Unexpected token < in JSON');
                }

                return answer.body;
            },
        };
    };

    return { fetch, requests };
}

/** A sync fed by the feed; apply() records each request. */
function polling(answers, options = {}) {
    const { fetch, requests } = fakeFetch(answers);
    const applied = [];
    const sync = createActionSync({ apply: (request) => applied.push(request), ...options, feed: { url: URL_, fetch, ...options.feed } });

    return { sync, requests, applied };
}

/** Let the fetch, its json() and the callbacks after them run. */
const settle = () => new Promise((resolve) => setImmediate(resolve));

/** Advance the clock and let any poll the timers started finish. */
async function wait(ms) {
    mock.timers.tick(ms);
    await settle();
}

let doc;

beforeEach(() => {
    mock.timers.enable({ apis: ['setTimeout', 'Date'], now: 1_000_000 });
    globalThis.location = { href: 'https://app.test/teams/acme/posts', origin: 'https://app.test' };
    doc = stubDocument();
});

afterEach(() => {
    mock.timers.reset();
    calls.length = 0;
    delete globalThis.location;
    delete globalThis.document;
});

describe('createActionSync({ feed })', () => {
    test('sends { since: null } first and applies nothing, then echoes the last now it received', async () => {
        const { requests, applied, sync } = polling([
            { body: { now: 5000, touches: [] } },
            { body: { now: 6000, touches: [] } },
        ]);

        await settle();
        await wait(150);

        assert.deepEqual(requests.map(({ body }) => body), [{ since: null }]);
        assert.deepEqual(applied, []);

        await wait(15_000);
        await wait(15_000);

        assert.deepEqual(requests.map(({ body }) => body), [{ since: null }, { since: 5000 }, { since: 6000 }]);

        sync.dispose();
    });

    test('a polled touch reaches apply() once, 150 ms later, coalesced like a done row', async () => {
        const { applied, sync } = polling([{ body: { now: 1, touches: [] } }, { body: { now: 2, touches: ['posts', 'stats'] } }]);

        await settle();
        await wait(15_000);

        assert.deepEqual(applied, []);

        sync.touch(['drafts']);
        await wait(150);

        assert.deepEqual(applied, [{ touches: ['posts', 'stats', 'drafts'], follow: null, how: 'reload' }]);

        sync.dispose();
    });

    test("with when: 'turn', polled touches still apply, since no turn will flush them", async () => {
        const { applied, sync } = polling([{ body: { now: 1, touches: [] } }, { body: { now: 2, touches: ['posts'] } }], { when: 'turn' });

        await settle();
        await wait(15_000);
        await wait(150);

        assert.deepEqual(applied, [{ touches: ['posts'], follow: null, how: 'reload' }]);

        sync.dispose();
    });

    test('a hidden page sends nothing and sets no timer; turning visible polls at once', async () => {
        const { requests, sync } = polling();

        await settle();
        doc.hide(true);
        await wait(10 * MINUTE);

        assert.equal(requests.length, 1);

        doc.hide(false);
        await settle();

        assert.equal(requests.length, 2);

        await wait(15_000);

        assert.equal(requests.length, 3);

        sync.dispose();
    });

    test('a page back after longer than the window reloads everything once when the server answers "*"', async () => {
        const { requests, applied, sync } = polling([{ body: { now: 1, touches: [] } }, { body: { now: 2, touches: ['*'] } }]);

        await settle();
        doc.hide(true);
        await wait(30 * MINUTE);
        doc.hide(false);
        await settle();
        await wait(150);

        assert.deepEqual(requests.map(({ body }) => body), [{ since: null }, { since: 1 }]);
        assert.deepEqual(applied, [{ touches: ['*'], follow: null, how: 'reload' }]);

        sync.dispose();
    });

    test('pauses after 10 minutes with no input, and the next input polls at once and restarts the interval', async () => {
        const { requests, sync } = polling();

        await settle();
        await wait(10 * MINUTE);

        const before = requests.length;

        await wait(15_000);
        await wait(10 * MINUTE);

        assert.equal(requests.length, before);

        doc.fire('pointerdown');
        await settle();

        assert.equal(requests.length, before + 1);

        await wait(15_000);

        assert.equal(requests.length, before + 2);

        doc.fire('keydown');
        await settle();

        // Input while active only moves the idle clock; it does not poll.
        assert.equal(requests.length, before + 2);

        sync.dispose();
    });

    test('keeps polling while there is input', async () => {
        const { requests, sync } = polling();

        await settle();

        for (let minute = 0; minute < 12; minute++) {
            doc.fire('keydown');
            await wait(15_000);
            await wait(15_000);
            await wait(15_000);
            await wait(15_000);
        }

        assert.equal(requests.length, 1 + 48);

        sync.dispose();
    });

    test('applies nothing from a response that arrives after dispose()', async () => {
        let answer;
        const pending = new Promise((resolve) => (answer = resolve));
        const applied = [];
        const sync = createActionSync({
            apply: (request) => applied.push(request),
            feed: { url: URL_, fetch: () => pending },
        });

        sync.dispose();
        answer({ status: 200, ok: true, json: async () => ({ now: 5, touches: ['posts'] }) });
        await settle();
        await wait(1000);

        assert.deepEqual(applied, []);
    });

    for (const status of [401, 403, 404, 419]) {
        test(`stops polling for good on a ${status}`, async () => {
            const { requests, sync } = polling([{ status, body: { message: 'x' } }]);

            await settle();
            await wait(10 * 15_000);
            doc.fire('pointerdown');
            doc.hide(true);
            doc.hide(false);
            await settle();

            assert.equal(requests.length, 1);
            assert.equal(doc.count(), 0);

            sync.dispose();
        });
    }

    for (const [label, answer] of [
        ['a 429', { status: 429, body: {} }],
        ['a 500', { status: 500, body: {} }],
        ['a 503', { status: 503, body: {} }],
        ['a network error', new TypeError('Failed to fetch')],
        ['a body that is not JSON', { status: 200, body: undefined }],
    ]) {
        test(`waits for the next interval after ${label}`, async () => {
            const { requests, applied, sync } = polling([answer, { body: { now: 9, touches: ['posts'] } }]);

            await settle();
            await wait(14_999);

            assert.equal(requests.length, 1);

            await wait(1);
            await wait(150);

            assert.equal(requests.length, 2);
            assert.deepEqual(requests[1].body, { since: null });
            assert.deepEqual(applied, [{ touches: ['posts'], follow: null, how: 'reload' }]);

            sync.dispose();
        });
    }

    test('drops touches that are not strings, and a body without a numeric now', async () => {
        const { requests, applied, sync } = polling([
            { body: { now: '5', touches: ['posts'] } },
            { body: { now: 7, touches: ['posts', 3, null, { key: 'x' }] } },
        ]);

        await settle();
        await wait(15_000);
        await wait(150);

        assert.deepEqual(requests.map(({ body }) => body), [{ since: null }, { since: null }]);
        assert.deepEqual(applied, [{ touches: ['posts'], follow: null, how: 'reload' }]);

        sync.dispose();
    });

    test('posts JSON with same-origin credentials, and sends the XSRF header to its own origin only', async () => {
        const same = polling();

        await settle();
        same.sync.dispose();

        const other = polling([], { feed: { url: 'https://evil.test/actions/_changes' } });

        await settle();
        other.sync.dispose();

        const [{ url, init }] = same.requests;

        assert.equal(url, URL_);
        assert.equal(init.method, 'POST');
        assert.equal(init.credentials, 'same-origin');
        assert.deepEqual(init.headers, { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': 'abc=' });
        assert.deepEqual(other.requests[0].init.headers, { Accept: 'application/json', 'Content-Type': 'application/json' });
    });

    test('takes its interval from the option', async () => {
        const { requests, sync } = polling([], { feed: { interval: 1000 } });

        await settle();
        await wait(1000);
        await wait(1000);

        assert.equal(requests.length, 3);

        sync.dispose();
    });

    test('dispose() stops the poller and removes its three listeners', async () => {
        const { requests, sync } = polling();

        await settle();

        assert.equal(doc.count(), 3);

        sync.dispose();
        await wait(10 * 15_000);
        doc.fire('pointerdown');

        assert.equal(requests.length, 1);
        assert.equal(doc.count(), 0);
    });

    test('never polls where there is no document (SSR)', async () => {
        delete globalThis.document;

        const { requests, sync } = polling();

        await wait(10 * 15_000);

        assert.equal(requests.length, 0);

        sync.dispose();
    });
});

describe('the feed through inertiaApply', () => {
    test('waits while an editor is dirty, and runs when it turns clean', async () => {
        const key = Symbol('editor');
        const { fetch } = fakeFetch([{ body: { now: 1, touches: [] } }, { body: { now: 2, touches: ['posts'] } }]);
        const sync = createActionSync({ apply: inertiaApply, feed: { url: URL_, fetch } });

        editors.set(key, true);

        try {
            await settle();
            await wait(15_000);
            await wait(1000);

            assert.deepEqual(calls, []);
        } finally {
            editors.set(key, false);
        }

        await wait(150);

        assert.deepEqual(calls.map(([name]) => name), ['flushByCacheTags', 'reload']);

        sync.dispose();
    });
});
