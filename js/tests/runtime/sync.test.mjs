import assert from 'node:assert/strict';
import { afterEach, beforeEach, describe, mock, test } from 'node:test';
import { actionParts, actionRows, createActionSync, editors, messageSegments, onTouched } from '../../dist/index.js';

/** A data-action part as the server sends it. */
const row = (id, data) => ({ type: 'data-action', id, data: { action: 'create-post', label: 'Saving…', effect: 'write', ...data } });
const done = (id, data = {}) => row(id, { status: 'done', ...data });

/** A sync whose apply() records each request and answers with `answer`. */
function recording(options = {}, answer = () => undefined) {
    const requests = [];
    const waiting = [];
    const sync = createActionSync({
        apply: (request) => {
            requests.push(request);

            return answer(request);
        },
        onWaiting: (value) => waiting.push(value),
        ...options,
    });

    return { sync, requests, waiting };
}

/** Let settled promises run their callbacks. */
const settle = () => new Promise((resolve) => setImmediate(resolve));

beforeEach(() => {
    mock.timers.enable({ apis: ['setTimeout'] });
    globalThis.location = { href: 'https://app.test/posts', origin: 'https://app.test', assign: () => {} };
});

afterEach(() => {
    mock.timers.reset();
    delete globalThis.location;
});

describe('createActionSync()', () => {
    test('makes one apply() of the done rows written within 150 ms, with every key', () => {
        const { sync, requests } = recording();

        sync.onPart(done('a:1', { touches: ['posts'] }));
        mock.timers.tick(100);
        sync.onPart(done('a:2', { touches: ['stats', 'posts'] }));
        mock.timers.tick(149);

        assert.deepEqual(requests, []);

        mock.timers.tick(1);

        assert.deepEqual(requests, [{ touches: ['posts', 'stats'], follow: null, how: 'reload' }]);

        mock.timers.tick(1000);

        assert.equal(requests.length, 1);
    });

    test('ignores every status but done', () => {
        const { sync, requests } = recording();

        for (const status of ['running', 'refused', 'failed', 'ended']) {
            sync.onPart(row('a:1', { status, touches: ['posts'], link: { url: '/posts/1', follow: true } }));
        }

        mock.timers.tick(1000);

        assert.deepEqual(requests, []);
    });

    test("with when: 'turn', applies only after flush()", () => {
        const { sync, requests } = recording({ when: 'turn' });

        sync.onPart(done('a:1', { touches: ['posts'] }));
        sync.touch(['drafts']);
        mock.timers.tick(1000);

        assert.deepEqual(requests, []);

        sync.flush();
        mock.timers.tick(150);

        assert.deepEqual(requests, [{ touches: ['posts', 'drafts'], follow: null, how: 'reload' }]);
    });

    test('flush() also runs a pending live refresh, and does nothing when nothing is pending', () => {
        const { sync, requests, waiting } = recording();

        sync.flush();
        mock.timers.tick(150);

        assert.deepEqual(requests, []);
        assert.deepEqual(waiting, []);

        sync.touch(['posts']);
        sync.flush();
        mock.timers.tick(150);

        assert.deepEqual(requests.map((request) => request.touches), [['posts']]);
    });

    test('passes how through', () => {
        const { sync, requests } = recording({ how: 'remount' });

        sync.touch(['profile']);
        mock.timers.tick(150);

        assert.equal(requests[0].how, 'remount');
    });

    test('holds for a dirty editor: onWaiting(true) once, and no apply() until apply() is called by hand', () => {
        const key = Symbol('editor');
        const { sync, requests, waiting } = recording();

        editors.set(key, true);

        try {
            sync.onPart(done('a:1', { touches: ['posts'] }));
            mock.timers.tick(150);
            sync.onPart(done('a:2', { touches: ['stats'] }));
            sync.flush();
            mock.timers.tick(10_000);

            assert.deepEqual(requests, []);
            assert.deepEqual(waiting, [true]);

            sync.apply();

            assert.deepEqual(requests, [{ touches: ['posts', 'stats'], follow: null, how: 'reload' }]);
            assert.deepEqual(waiting, [true, false]);
        } finally {
            editors.set(key, false);
        }
    });

    test('reads the hold when the refresh is due, so an editor dirtied inside the window still holds it', () => {
        const key = Symbol('editor');
        const { sync, requests, waiting } = recording();

        try {
            sync.touch(['posts']);
            mock.timers.tick(100);
            editors.set(key, true);
            mock.timers.tick(50);

            assert.deepEqual(requests, []);
            assert.deepEqual(waiting, [true]);
        } finally {
            editors.set(key, false);
        }
    });

    test('runs a held refresh by itself 150 ms after the editors turn clean, and stops watching once it ran', () => {
        const first = Symbol('first');
        const second = Symbol('second');
        const { sync, requests, waiting } = recording();

        editors.set(first, true);
        editors.set(second, true);

        try {
            sync.touch(['posts']);
            mock.timers.tick(150);
            editors.set(first, false);
            mock.timers.tick(1000);

            assert.deepEqual(requests, []);

            editors.set(second, false);
            mock.timers.tick(149);

            assert.deepEqual(requests, []);

            mock.timers.tick(1);

            assert.deepEqual(requests, [{ touches: ['posts'], follow: null, how: 'reload' }]);
            assert.deepEqual(waiting, [true, false]);

            editors.set(first, true);
            editors.set(first, false);
            mock.timers.tick(1000);

            assert.equal(requests.length, 1);
        } finally {
            editors.set(first, false);
            editors.set(second, false);
        }
    });

    test('keeps it held when an editor turns dirty again inside the window, or blocked() still holds it', () => {
        const key = Symbol('editor');
        let blocked = false;
        const { sync, requests, waiting } = recording({ blocked: () => blocked });

        editors.set(key, true);

        try {
            sync.touch(['posts']);
            mock.timers.tick(150);
            editors.set(key, false);
            mock.timers.tick(100);
            editors.set(key, true);
            mock.timers.tick(1000);

            assert.deepEqual(requests, []);

            blocked = true;
            editors.set(key, false);
            mock.timers.tick(1000);

            assert.deepEqual(requests, []);
            assert.deepEqual(waiting, [true]);

            // The sync cannot see the host's own flag change: the host calls flush() when it clears.
            blocked = false;
            sync.flush();
            mock.timers.tick(150);

            assert.deepEqual(requests.map((request) => request.touches), [['posts']]);
            assert.deepEqual(waiting, [true, false]);
        } finally {
            editors.set(key, false);
        }
    });

    test('with resumeWhenClean: false, a held refresh waits for apply(), a done row or flush()', () => {
        const key = Symbol('editor');
        const { sync, requests, waiting } = recording({ resumeWhenClean: false });

        editors.set(key, true);

        try {
            sync.touch(['posts']);
            mock.timers.tick(150);
            editors.set(key, false);
            mock.timers.tick(10_000);

            assert.deepEqual(requests, []);
            assert.deepEqual(waiting, [true]);

            sync.onPart(done('a:1', { touches: ['stats'] }));
            mock.timers.tick(150);

            assert.deepEqual(requests.map((request) => request.touches), [['posts', 'stats']]);
            assert.deepEqual(waiting, [true, false]);
        } finally {
            editors.set(key, false);
        }
    });

    test('dispose() stops watching the editors too', () => {
        const key = Symbol('editor');
        const { sync, requests } = recording();

        editors.set(key, true);

        try {
            sync.touch(['posts']);
            mock.timers.tick(150);
            sync.dispose();
            editors.set(key, false);
            mock.timers.tick(1000);

            assert.deepEqual(requests, []);
        } finally {
            editors.set(key, false);
        }
    });

    test('runs one apply() at a time: a pending one delays the next by 150 ms, and keys added meanwhile join it', async () => {
        let finish;
        const { sync, requests } = recording({}, () => new Promise((resolve) => (finish = resolve)));

        sync.touch(['posts']);
        mock.timers.tick(150);

        assert.equal(requests.length, 1);

        sync.touch(['stats']);
        mock.timers.tick(150);
        sync.touch(['drafts']);
        mock.timers.tick(150);
        await settle();

        assert.equal(requests.length, 1);

        finish();
        await settle();
        mock.timers.tick(150);

        assert.deepEqual(requests.map((request) => request.touches), [['posts'], ['stats', 'drafts']]);
    });

    test('an apply() that throws or rejects is rethrown on its own and never blocks the next', async () => {
        const queued = [];
        const original = globalThis.queueMicrotask;
        let failure = () => {
            throw new Error('apply threw');
        };
        const { sync, requests } = recording({}, (request) => failure(request));

        globalThis.queueMicrotask = (callback) => queued.push(callback);

        try {
            sync.touch(['posts']);
            mock.timers.tick(150);
            await settle();

            failure = () => Promise.reject(new Error('apply rejected'));
            sync.touch(['stats']);
            mock.timers.tick(150);
            await settle();

            failure = () => undefined;
            sync.touch(['drafts']);
            mock.timers.tick(150);
        } finally {
            globalThis.queueMicrotask = original;
        }

        assert.deepEqual(requests.map((request) => request.touches), [['posts'], ['stats'], ['drafts']]);
        assert.equal(queued.length, 2);
        assert.throws(() => queued[0](), /apply threw/);
        assert.throws(() => queued[1](), /apply rejected/);
    });

    test('follows a same-origin link only when the row says follow: true', async () => {
        const { sync, requests } = recording();

        sync.onPart(done('a:1', { touches: ['posts'], link: { url: '/posts/42/edit', follow: false } }));
        mock.timers.tick(150);
        await settle();
        sync.onPart(done('a:2', { link: { url: 'https://evil.test/posts/42', follow: true } }));
        sync.onPart(done('a:3', { link: { url: '//evil.test/posts/42', follow: true } }));
        mock.timers.tick(150);
        sync.onPart(done('a:4', { touches: ['posts'], link: { url: '/posts/42/edit', follow: true } }));
        mock.timers.tick(150);

        assert.deepEqual(requests, [
            { touches: ['posts'], follow: null, how: 'reload' },
            { touches: ['posts'], follow: 'https://app.test/posts/42/edit', how: 'reload' },
        ]);
    });

    test('apply() by hand with nothing pending runs nothing', () => {
        const { sync, requests } = recording();

        sync.apply();

        assert.deepEqual(requests, []);
    });

    test('dispose() stops the timer', () => {
        const { sync, requests } = recording();

        sync.touch(['posts']);
        sync.dispose();
        mock.timers.tick(1000);

        assert.deepEqual(requests, []);
    });
});

describe("createActionSync()'s default apply()", () => {
    test('hands the touches to the onTouched() handlers with a null definition', () => {
        const seen = [];
        const unsubscribe = onTouched((touches, definition) => seen.push([touches, definition]));

        try {
            const sync = createActionSync();

            sync.onPart(done('a:1', { touches: ['*'] }));
            mock.timers.tick(150);

            assert.deepEqual(seen, [[['*'], null]]);
        } finally {
            unsubscribe();
        }
    });

    test('follows a link with location.assign(), and leaves the touches to the new page', () => {
        const assigned = [];
        const seen = [];
        const unsubscribe = onTouched((touches) => seen.push(touches));

        globalThis.location.assign = (url) => assigned.push(url);

        try {
            const sync = createActionSync();

            sync.onPart(done('a:1', { touches: ['posts'], link: { url: '/posts/42/edit', follow: true } }));
            mock.timers.tick(150);

            assert.deepEqual(assigned, ['https://app.test/posts/42/edit']);
            assert.deepEqual(seen, []);
        } finally {
            unsubscribe();
        }
    });
});

describe('unbound members', () => {
    test('every function of editors, actionParts and a sync works destructured, with no this', async () => {
        const requests = [];
        const heard = [];
        const { set, dirty, subscribe } = editors;
        const { emit, end, subscribe: listen } = actionParts;
        const { onPart, touch, flush, apply, dispose } = createActionSync({ apply: (request) => requests.push(request) });
        const key = Symbol('editor');
        const unsubscribe = subscribe(() => heard.push(dirty()));
        const unlisten = listen({ part: onPart, end: flush });

        try {
            set(key, true);
            set(key, false);
            emit(done('a:1', { touches: ['posts'] }));
            end();
            mock.timers.tick(150);
            await settle();
            touch(['stats']);
            apply();
            touch(['drafts']);
            dispose();
            mock.timers.tick(1000);
        } finally {
            unsubscribe();
            unlisten();
            set(key, false);
        }

        assert.deepEqual(heard, [true, false]);
        assert.deepEqual(requests.map((request) => request.touches), [['posts'], ['stats']]);
    });
});

describe('editors', () => {
    test('is dirty while any editor is, and tells its subscribers of each change', () => {
        const first = Symbol('first');
        const second = Symbol('second');
        let changes = 0;
        const unsubscribe = editors.subscribe(() => changes++);

        editors.set(first, true);
        editors.set(first, true);
        editors.set(second, true);
        editors.set(first, false);

        assert.equal(editors.dirty(), true);

        editors.set(second, false);
        unsubscribe();
        editors.set(first, true);
        editors.set(first, false);

        assert.equal(editors.dirty(), false);
        assert.equal(changes, 4);
    });
});

describe('actionRows()', () => {
    test('gives one row per id, in order, with the last data sent', () => {
        const message = {
            parts: [
                { type: 'step-start' },
                row('a:1', { status: 'running' }),
                row('a:2', { status: 'running', action: 'CountDrafts', label: 'Counting…', effect: undefined }),
                { type: 'text', text: 'Saved.' },
                done('a:1', { label: 'Draft saved', touches: ['posts'] }),
                done('a:2', { action: 'CountDrafts', label: 'Counted', effect: undefined }),
            ],
        };

        assert.deepEqual(actionRows(message), [
            { id: 'a:1', action: 'create-post', label: 'Draft saved', effect: 'write', status: 'done' },
            { id: 'a:2', action: 'CountDrafts', label: 'Counted', effect: undefined, status: 'done' },
        ]);
    });

    test('ignores a part without a string id or without data, and reads no message as no rows', () => {
        const message = {
            parts: [
                { type: 'data-action', data: { action: 'x', label: 'x', status: 'done' } },
                { type: 'data-action', id: 7, data: { action: 'x', label: 'x', status: 'done' } },
                { type: 'data-action', id: 'a:9', data: null },
                { type: 'data-other', id: 'a:1', data: {} },
            ],
        };

        assert.deepEqual(actionRows(message), []);
        assert.deepEqual(actionRows(undefined), []);
    });

    test('with settled, shows a row still running as ended, and leaves every other status as it is', () => {
        const message = {
            parts: [row('a:1', { status: 'running' }), done('a:2', { touches: ['posts'] }), row('a:3', { status: 'refused', note: 'Not done' })],
        };

        assert.deepEqual(actionRows(message, { settled: true }).map((current) => [current.id, current.status, current.label]), [
            ['a:1', 'ended', 'Saving…'],
            ['a:2', 'done', 'Saving…'],
            ['a:3', 'refused', 'Saving…'],
        ]);
        assert.deepEqual(actionRows(message).map((current) => current.status), ['running', 'done', 'refused']);
        assert.deepEqual(actionRows(message, { settled: false }).map((current) => current.status), ['running', 'done', 'refused']);
        assert.equal(message.parts[0].data.status, 'running');
    });
});

describe('messageSegments()', () => {
    const text = (value) => ({ type: 'text', text: value, state: 'done' });
    const strip = (segments) => segments.map((segment) => (segment.type === 'text' ? segment.text : segment.rows.map((current) => `${current.id}:${current.status}`)));

    test('gives the words and the rows in stream order, one run per group of consecutive rows', () => {
        const message = {
            parts: [
                { type: 'step-start' },
                text('Let me look.'),
                row('a:1', { status: 'done' }),
                { type: 'step-start' },
                row('a:2', { status: 'running' }),
                { type: 'data-other', id: 'x', data: {} },
                row('a:3', { status: 'done' }),
                { type: 'step-start' },
                text('Saved two drafts.'),
                row('a:4', { status: 'running' }),
            ],
        };

        assert.deepEqual(strip(messageSegments(message)), [
            'Let me look.',
            ['a:1:done', 'a:2:running', 'a:3:done'],
            'Saved two drafts.',
            ['a:4:running'],
        ]);
        assert.deepEqual(messageSegments(message)[1].rows[0], { id: 'a:1', action: 'create-post', label: 'Saving…', effect: 'write', status: 'done' });
    });

    test('keeps a later part for a row in the run where the row first appeared', () => {
        // useChat updates a part in place; a reader that appends every part sends the update again later on.
        const message = {
            parts: [row('a:1', { status: 'running' }), text('Checking.'), done('a:1', { label: 'Draft saved' }), row('a:2', { status: 'running' }), done('a:2')],
        };

        assert.deepEqual(strip(messageSegments(message)), [['a:1:done'], 'Checking.', ['a:2:done']]);
        assert.equal(messageSegments(message)[0].rows[0].label, 'Draft saved');
    });

    test('leaves out text that is only white space, so it never splits a run', () => {
        const message = { parts: [text(''), row('a:1', { status: 'done' }), text('\n\n'), row('a:2', { status: 'done' }), text(' Done. ')] };

        assert.deepEqual(strip(messageSegments(message)), [['a:1:done', 'a:2:done'], ' Done. ']);
    });

    test('with settled, shows a row still running as ended', () => {
        const message = { parts: [row('a:1', { status: 'running' }), text('Stopped.'), done('a:2')] };

        assert.deepEqual(strip(messageSegments(message, { settled: true })), [['a:1:ended'], 'Stopped.', ['a:2:done']]);
        assert.deepEqual(strip(messageSegments(message)), [['a:1:running'], 'Stopped.', ['a:2:done']]);
    });

    test('gives the words of a user message, and reads no message as nothing', () => {
        assert.deepEqual(messageSegments({ parts: [text('Draft a post about tides')] }), [{ type: 'text', text: 'Draft a post about tides' }]);
        assert.deepEqual(messageSegments(undefined), []);
        assert.deepEqual(messageSegments({ parts: [{ type: 'text' }, { type: 'data-action', id: 'a:1', data: null }] }), []);
    });
});
