import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { afterEach, describe, mock, test } from 'node:test';
import { action, actionRows, applyActionPart, callAction, createActionSync, editors, messageSegments, sameOriginUrl, uri } from '../../dist/index.js';

/*
 * A hostile value in a route parameter, and a hostile URL in a definition. A parameter fills exactly one path
 * segment, and the app's CSRF token never leaves its origin unless the caller chose credentials: 'include'.
 *
 * A hostile data-action part: whatever arrives on the wire, a row links and follows only a same-origin http(s) URL
 * given as a string, renders its words as text, and waits for an editor with unsaved work.
 */

// The real React and its server renderer; Inertia is stubbed, its <Link> as an anchor that says where it came from.
const require = createRequire(import.meta.url);
const React = require('react');
const { renderToStaticMarkup } = require('react-dom/server');

mock.module('@inertiajs/core', { namedExports: { router: { reload() {}, visit() {}, flushAll() {}, flushByCacheTags() {} }, HttpResponseError: class extends Error {} } });
mock.module('@inertiajs/react', {
    namedExports: {
        Link: ({ href, children }) => React.createElement('a', { href: typeof href === 'string' ? href : `OBJECT:${JSON.stringify(href)}`, 'data-inertia-link': '' }, children),
        useHttp: () => ({}),
    },
});

const { ActionActivity } = await import('../../dist/react.js');

/** A done data-action part with the given link. */
const linked = (link, id = 'a:1') => ({ type: 'data-action', id, data: { action: 'create-post', label: 'Draft saved', status: 'done', link } });

/** A fetch that records each call and answers 200 with an empty object. */
function recordingFetch() {
    const calls = [];

    const fetch = async (url, init) => {
        calls.push({ url, init });

        return new Response('{}', { status: 200, headers: { 'Content-Type': 'application/json' } });
    };

    return { fetch, calls };
}

afterEach(() => {
    delete globalThis.location;
    delete globalThis.document;
});

describe('uri()', () => {
    test('refuses a parameter a URL parser would resolve out of its segment', () => {
        for (const value of ['..', '.']) {
            assert.throws(() => uri('/teams/{team}/actions/list-team-posts', { team: value }), /cannot be "\.\.?"/);
        }

        for (const value of ['%2E%2E', '.%2e']) {
            const url = uri('/teams/{team}/actions/list-team-posts', { team: value });

            assert.equal(new URL(url, 'https://app.test').pathname, url);
        }
    });

    test('keeps every other parameter inside its own segment', () => {
        const url = uri('/teams/{team}/actions/list-team-posts', { team: '../admin?x=1#y' });

        assert.equal(url, '/teams/..%2Fadmin%3Fx%3D1%23y/actions/list-team-posts');
        assert.equal(new URL(url, 'https://app.test').pathname, url);
    });
});

describe('the XSRF header', () => {
    test('never goes to a URL a parser reads as another origin', async () => {
        globalThis.location = { href: 'https://app.test/notes', origin: 'https://app.test' };
        globalThis.document = { cookie: 'XSRF-TOKEN=secret' };

        const { fetch, calls } = recordingFetch();

        for (const url of ['//evil.test/actions/x', '\\\\evil.test/actions/x', '/\\evil.test/actions/x', 'https://app.test.evil.test/x', 'http://app.test/x']) {
            await callAction(action({ name: 'x', method: 'post', url, touches: [] }), {}, { fetch });
        }

        assert.deepEqual(calls.map((call) => call.init.headers['X-XSRF-TOKEN']), [undefined, undefined, undefined, undefined, undefined]);
        assert.deepEqual(calls.map((call) => call.init.credentials), ['same-origin', 'same-origin', 'same-origin', 'same-origin', 'same-origin']);
    });
});

describe('a hostile data-action part', () => {
    const hostile = [
        ['an object that Inertia reads as a url and method', { url: 'https://evil.test/phish', method: 'get' }],
        ['a same-origin url and method', { url: '/account', method: 'delete' }],
        ['a number', 42],
        ['an array', ['/posts/42']],
        ['null', null],
        ['a blob URL of this origin', 'blob:https://app.test/0b1c'],
        ['javascript', 'javascript:alert(1)'],
        ['a data URL', 'data:text/html,<script>alert(1)</script>'],
        ['a cross-origin URL', 'https://evil.test/x'],
        ['a protocol-relative URL', '//evil.test/x'],
    ];

    test('keeps no link whose url is not a same-origin http(s) string, in the rows or the segments', () => {
        globalThis.location = { href: 'https://app.test/posts', origin: 'https://app.test' };

        for (const [name, url] of hostile) {
            assert.equal(actionRows({ parts: [linked({ url, follow: true })] })[0].link, undefined, name);
            assert.equal(messageSegments({ parts: [linked({ url, follow: true })] })[0].rows[0].link, undefined, name);
        }

        for (const link of ['javascript:alert(1)', null, 42, { follow: true }]) {
            assert.equal(applyActionPart([], linked(link))[0].link, undefined, JSON.stringify(link));
        }

        assert.deepEqual(actionRows({ parts: [linked({ url: '/posts/42/edit', follow: false })] })[0].link, { url: '/posts/42/edit', follow: false });
        assert.deepEqual(messageSegments({ parts: [linked({ url: '/posts/42/edit', follow: false })] })[0].rows[0].link, { url: '/posts/42/edit', follow: false });
    });

    test('keeps no javascript: or data: link on a page whose own origin is opaque', () => {
        // A sandboxed frame: its origin, and a javascript: or data: URL's, are both "null".
        globalThis.location = { href: 'https://app.test/posts', origin: 'null' };

        for (const url of ['javascript:alert(1)', 'data:text/html,<script>alert(1)</script>', '/posts/42']) {
            assert.equal(sameOriginUrl(url), null, url);
            assert.equal(actionRows({ parts: [linked({ url, follow: true })] })[0].link, undefined, url);
        }
    });

    test('never pollutes a prototype through a key named __proto__', () => {
        globalThis.location = { href: 'https://app.test/posts', origin: 'https://app.test' };

        const part = JSON.parse('{"type":"data-action","id":"a:1","data":{"action":"x","label":"L","status":"done","__proto__":{"polluted":true},"link":{"url":"/a","follow":false,"__proto__":{"polluted":true}}}}');
        const [row] = actionRows({ parts: [part] });

        assert.equal({}.polluted, undefined);
        assert.equal(Object.getPrototypeOf(row), Object.prototype);
        assert.equal(row.polluted, undefined);
    });

    test('never follows a link that is not a same-origin http(s) string, nor one whose follow is not true', () => {
        mock.timers.enable({ apis: ['setTimeout'] });
        globalThis.location = { href: 'https://app.test/posts', origin: 'https://app.test' };

        const requests = [];
        const sync = createActionSync({ apply: (request) => requests.push(request) });

        hostile.forEach(([, url], index) => sync.onPart(linked({ url, follow: true }, `a:${index}`)));
        sync.onPart(linked({ url: '/posts/42/edit', follow: 'true' }, 'a:99'));
        mock.timers.tick(150);
        mock.timers.reset();

        assert.deepEqual(requests, []);
    });

    test('holds a followed link, like a reload, while an editor has unsaved work', () => {
        mock.timers.enable({ apis: ['setTimeout'] });
        globalThis.location = { href: 'https://app.test/posts', origin: 'https://app.test' };

        const editor = Symbol('editor');
        const requests = [];
        const sync = createActionSync({ apply: (request) => requests.push(request) });

        editors.set(editor, true);
        sync.onPart(linked({ url: '/posts/42/edit', follow: true }));
        mock.timers.tick(150);
        sync.flush();
        mock.timers.tick(150);

        assert.deepEqual(requests, []);

        editors.set(editor, false);
        sync.apply();
        mock.timers.reset();

        assert.deepEqual(requests, [{ touches: [], follow: 'https://app.test/posts/42/edit', how: 'reload' }]);
    });
});

describe('<ActionActivity> with hostile rows', () => {
    const markup = (rows) => renderToStaticMarkup(React.createElement(ActionActivity, { rows }));

    test('renders a label, a note and a status as text, never as markup', () => {
        const html = markup([{ id: 'a:1', action: 'x', label: '<img src=x onerror=alert(1)>', status: '"><script>alert(1)</script>', note: '<b>bold</b>' }]);

        assert.ok(!html.includes('<img'));
        assert.ok(!html.includes('<script'));
        assert.ok(!html.includes('<b>'));
        assert.match(html, /<bdi>&lt;img src=x onerror=alert\(1\)&gt;<\/bdi>/);
    });

    test("links a row only to the checked same-origin URL, never another origin's, nor an object Inertia would read as a url and a method", () => {
        globalThis.location = { href: 'https://app.test/posts', origin: 'https://app.test' };

        const html = markup([
            { id: 'a:1', action: 'x', label: 'Phish', status: 'done', link: { url: { url: 'https://evil.test/phish', method: 'get' }, follow: false } },
            { id: 'a:2', action: 'x', label: 'Delete', status: 'done', link: { url: { url: '/account', method: 'delete' }, follow: false } },
            { id: 'a:3', action: 'x', label: 'Blob', status: 'done', link: { url: 'blob:https://app.test/0b1c', follow: false } },
            { id: 'a:4', action: 'x', label: 'Saved', status: 'done', link: { url: '/posts/42/edit', follow: false } },
            { id: 'a:5', action: 'x', label: 'Elsewhere', status: 'done', link: { url: 'https://evil.test/x', follow: true } },
            { id: 'a:6', action: 'x', label: 'Protocol-relative', status: 'done', link: { url: '//evil.test/x', follow: true } },
        ]);

        assert.ok(!html.includes('OBJECT:'));
        assert.ok(!html.includes('evil.test'));
        assert.ok(!html.includes('blob:'));
        assert.equal(html.match(/<a /g)?.length, 1);
        assert.match(html, /<a href="https:\/\/app.test\/posts\/42\/edit" data-inertia-link=""><bdi>Saved<\/bdi><\/a>/);
    });
});

/*
 * A broken host or a busy page must never make the feed poller hammer the server: whatever interval the host passes,
 * however often the page turns visible or gets input, and whatever the server answers, it sends at most one poll a
 * second, and one at a time.
 */
describe('the feed poller', () => {
    /** A stub document whose visibility a test flips, firing the poller's listeners. */
    function stubDocument() {
        const listeners = new Map();

        globalThis.document = {
            hidden: false,
            cookie: '',
            addEventListener: (type, listener) => listeners.set(type, [...(listeners.get(type) ?? []), listener]),
            removeEventListener: (type, listener) => listeners.set(type, (listeners.get(type) ?? []).filter((current) => current !== listener)),
        };

        return {
            fire: (type) => (listeners.get(type) ?? []).forEach((listener) => listener()),
            hide: (hidden) => {
                globalThis.document.hidden = hidden;
                (listeners.get('visibilitychange') ?? []).forEach((listener) => listener());
            },
        };
    }

    /** A fetch that answers each poll at once, or holds every answer until release() when held. */
    function feedFetch({ touches = [], held = false } = {}) {
        const requests = [];
        let release = () => {};
        const gate = held ? new Promise((resolve) => (release = resolve)) : Promise.resolve();

        const fetch = async () => {
            requests.push(Date.now());
            await gate;

            return { status: 200, ok: true, json: async () => ({ now: requests.length, touches }) };
        };

        return { fetch, requests, release: () => release() };
    }

    const settle = () => new Promise((resolve) => setImmediate(resolve));

    /** Advance the clock in steps, letting every poll the timers start finish. */
    async function run(ms, step = 50) {
        for (let passed = 0; passed < ms; passed += step) {
            mock.timers.tick(step);
            await settle();
        }
    }

    let page;

    const enable = () => {
        mock.timers.enable({ apis: ['setTimeout', 'Date'], now: 1_000_000 });
        globalThis.location = { href: 'https://app.test/teams/acme/posts', origin: 'https://app.test' };
        page = stubDocument();
    };

    afterEach(() => mock.timers.reset());

    for (const interval of [0, -1, Number.NaN, 15, 0.5, Number.POSITIVE_INFINITY, 2 ** 31, 1e12]) {
        test(`never polls more than once a second, nor schedules past setTimeout's limit, with an interval of ${interval}`, async () => {
            enable();

            const delays = [];
            const mocked = globalThis.setTimeout;

            globalThis.setTimeout = (callback, ms, ...rest) => {
                delays.push(ms);

                return mocked(callback, ms, ...rest);
            };

            try {
                const { fetch, requests } = feedFetch();
                const sync = createActionSync({ apply: () => {}, feed: { url: '/teams/acme/actions/_changes', interval, fetch } });

                await settle();
                await run(5000);
                sync.dispose();

                assert.ok(requests.length <= 6, `${requests.length} polls in 5 s`);
                assert.ok(delays.every((ms) => ms >= 1000 && ms <= 2 ** 31 - 1), `delays ${delays.join(', ')}`);
            } finally {
                globalThis.setTimeout = mocked;
            }
        });
    }

    test('sends one request for a storm of visibility changes and input while a poll is in flight', async () => {
        enable();

        const { fetch, requests, release } = feedFetch({ held: true });
        const sync = createActionSync({ apply: () => {}, feed: { url: '/teams/acme/actions/_changes', fetch } });

        await settle();

        for (let i = 0; i < 20; i++) {
            page.hide(true);
            page.hide(false);
            page.fire('pointerdown');
            page.fire('keydown');
        }

        await settle();

        assert.equal(requests.length, 1);

        release();
        await run(1000);

        assert.equal(requests.length, 1);

        sync.dispose();
    });

    test('waits a second between polls however often the page turns visible', async () => {
        enable();

        const { fetch, requests } = feedFetch();
        const sync = createActionSync({ apply: () => {}, feed: { url: '/teams/acme/actions/_changes', fetch } });

        await settle();

        for (let i = 0; i < 40; i++) {
            page.hide(true);
            page.hide(false);
            await run(50);
        }

        // Two seconds of flipping: the first poll, then at most one a second.
        assert.ok(requests.length <= 3, `${requests.length} polls in 2 s`);
        assert.ok(requests.every((at, i) => i === 0 || at - requests[i - 1] >= 1000));

        sync.dispose();
    });

    test('a server answering "*" to every poll costs one apply per poll, never more', async () => {
        enable();

        const applied = [];
        const { fetch, requests } = feedFetch({ touches: ['*'] });
        const sync = createActionSync({ apply: (request) => applied.push(request), feed: { url: '/teams/acme/actions/_changes', fetch } });

        await settle();
        await run(61_000, 250);
        sync.dispose();

        // The first poll, then one every 15 s, each applied once 150 ms later.
        assert.equal(requests.length, 5);
        assert.equal(applied.length, 5);
    });
});
