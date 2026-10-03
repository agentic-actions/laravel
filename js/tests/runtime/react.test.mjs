import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { afterEach, beforeEach, describe, mock, test } from 'node:test';

// The real React and its server renderer, loaded before 'react' is mocked, render <ActionActivity>.
const require = createRequire(import.meta.url);
const React = require('react');
const { renderToStaticMarkup } = require('react-dom/server');

/** A stub Inertia router that records its calls. */
const calls = [];
const router = {
    flushAll: () => calls.push(['flushAll']),
    flushByCacheTags: (tags) => calls.push(['flushByCacheTags', tags]),
    reload: (...args) => calls.push(['reload', ...args]),
    visit: (...args) => calls.push(['visit', ...args]),
};

class HttpResponseError extends Error {
    constructor(message, response) {
        super(message);
        this.response = response;
    }
}

/** The smallest hook runtime: state kept by call order, across renders of one component; effects run after a render. */
let slots = [];
let cursor = 0;
let effects = [];

const render = (hook) => {
    cursor = 0;
    effects = [];

    const result = hook();

    effects.forEach((run) => run());

    return result;
};

/** Run every effect cleanup, as React does when the component unmounts. */
const unmount = () => {
    for (const slot of slots) {
        slot?.cleanup?.();
    }

    slots = [];
};

const useRef = (initial) => {
    const index = cursor++;
    slots[index] ??= { current: initial };

    return slots[index];
};

const useState = (initial) => {
    const index = cursor++;
    slots[index] ??= { value: typeof initial === 'function' ? initial() : initial };
    const slot = slots[index];

    return [slot.value, (value) => (slot.value = value)];
};

const useEffect = (effect, deps) => {
    const index = cursor++;
    const slot = (slots[index] ??= { deps: undefined, cleanup: undefined });

    if (slot.deps === undefined || deps.some((dep, position) => !Object.is(dep, slot.deps[position]))) {
        effects.push(() => {
            slot.cleanup?.();
            slot.cleanup = effect() ?? undefined;
            slot.deps = deps;
        });
    }
};

/** A stub Inertia <Link>: an anchor that says it came from Link. */
const Link = ({ href, children }) => React.createElement('a', { href, 'data-inertia-link': '' }, children);

/** useHttp stub: records the pair it was given, the options of each submit and each validate() call, and answers submits with `answer`. */
let answer = async () => undefined;
const submits = [];
const pairs = [];
const validations = [];
let errors = {};

const useHttp = (pair, data) => {
    pairs.push(pair);

    const form = {
        data,
        errors,
        processing: false,
        submit: async (options) => {
            submits.push(options);

            return answer(options);
        },
        validate: (field, config) => {
            validations.push([field, config]);

            return form;
        },
    };

    return form;
};

/**
 * Answer the latest validate() call as laravel-precognition's client does: onStart, then the handler for the status
 * (onForbidden for a 403 and so on), or a rejection nobody catches when the config has none.
 */
const answerValidation = async (status, data) => {
    const [field, config] = validations.at(-1);
    const options = typeof field === 'object' && field !== null && !('target' in field) ? field : config;
    const response = { status, data, headers: { precognition: 'true' } };
    const handler = { 401: 'onUnauthorized', 403: 'onForbidden', 404: 'onNotFound', 409: 'onConflict', 423: 'onLocked' }[status];

    options?.onStart?.();

    return options?.[handler] ? options[handler](response, new HttpResponseError(`Request failed with status ${status}`, response)) : Promise.reject(new HttpResponseError(`Request failed with status ${status}`, response));
};

mock.module('react', { namedExports: { createElement: React.createElement, useEffect, useId: () => ':r0:', useRef, useState } });
mock.module('@inertiajs/core', { namedExports: { router, HttpResponseError } });
mock.module('@inertiajs/react', { namedExports: { Link, useHttp } });

const { action, actionParts, editors, notifyTouched } = await import('../../dist/index.js');
const { installInertiaReload } = await import('../../dist/inertia.js');
const { ActionActivity, useAction, useActionEdits, useActionSync } = await import('../../dist/react.js');

const createNote = action({ name: 'create-note', method: 'post', url: '/actions/create-note', touches: ['notes'] });

beforeEach(() => {
    slots = [];
    errors = {};
    calls.length = 0;
    submits.length = 0;
    pairs.length = 0;
    validations.length = 0;
    answer = async () => undefined;
});

afterEach(() => {
    unmount();
    calls.length = 0;
    delete globalThis.location;
});

describe('importing /react', () => {
    test('installs the Inertia reload, once', () => {
        const once = [
            ['flushByCacheTags', ['posts']],
            ['reload', { only: ['posts'] }],
        ];

        notifyTouched(['posts'], createNote);

        assert.deepEqual(calls, once);

        calls.length = 0;
        installInertiaReload();
        notifyTouched(['posts'], createNote);

        assert.deepEqual(calls, once);
    });
});

describe('useAction()', () => {
    test('hands the definition itself to useHttp, with the initial data', () => {
        const note = render(() => useAction(createNote, { title: '', body: '' }));

        assert.equal(pairs[0], createNote);
        assert.deepEqual(note.data, { title: '', body: '' });
        assert.equal(note.refusal, null);
    });

    test('mints the key on the first run() and reuses it until one succeeds', async () => {
        answer = async () => {
            throw new HttpResponseError('Request failed with status 409', { status: 409, data: '{"message":"Try again."}', headers: {} });
        };

        const note = render(() => useAction(createNote, { title: 'Hi', body: 'x' }));

        await note.run();
        await note.run();

        const [first, second] = submits.map((options) => options.headers['Idempotency-Key']);

        assert.match(first, /^[0-9a-f-]{36}$/);
        assert.equal(second, first);

        answer = async (options) => {
            options.onSuccess({ id: 1, title: 'Hi' }, { status: 200, data: '', headers: {} });

            return { id: 1, title: 'Hi' };
        };

        await note.run();
        await note.run();

        assert.equal(submits[2].headers['Idempotency-Key'], first);
        assert.notEqual(submits[3].headers['Idempotency-Key'], first);
    });

    test('starts from a given idempotency key', async () => {
        const note = render(() => useAction(createNote, { title: 'Hi', body: 'x' }, { idempotencyKey: 'from-the-page' }));

        await note.run({ headers: { 'X-Trace': '1' } });

        assert.equal(submits[0].headers['Idempotency-Key'], 'from-the-page');
        assert.equal(submits[0].headers['X-Trace'], '1');
    });

    test('resolves the output, notifies the touches and calls the caller back on success', async () => {
        const seen = [];

        answer = async (options) => {
            options.onSuccess({ id: 7, title: 'Hi' }, { status: 200, data: '', headers: {} });

            return { id: 7, title: 'Hi' };
        };

        const note = render(() => useAction(createNote, { title: 'Hi', body: 'x' }));
        const output = await note.run({ onSuccess: (result) => seen.push(result) });

        assert.deepEqual(output, { id: 7, title: 'Hi' });
        assert.deepEqual(seen, [{ id: 7, title: 'Hi' }]);
        assert.deepEqual(calls, [
            ['flushByCacheTags', ['notes']],
            ['reload', { only: ['notes'] }],
        ]);
    });

    test('turns a 4xx into refusal and resolves undefined', async () => {
        answer = async () => {
            throw new HttpResponseError('Request failed with status 409', { status: 409, data: '{"message":"You already have a post with that title."}', headers: {} });
        };

        const hook = () => useAction(createNote, { title: 'Hi', body: 'x' });

        assert.equal(await render(hook).run(), undefined);
        assert.equal(render(hook).refusal, 'You already have a post with that title.');

        answer = async () => {
            throw new HttpResponseError('Request failed with status 404', { status: 404, data: '<html>', headers: {} });
        };

        await render(hook).run();

        assert.equal(render(hook).refusal, '404');
        assert.deepEqual(calls, []);
    });

    test('rethrows a 5xx and any other error', async () => {
        const note = render(() => useAction(createNote, { title: 'Hi', body: 'x' }));

        answer = async () => {
            throw new HttpResponseError('Request failed with status 500', { status: 500, data: '', headers: {} });
        };

        await assert.rejects(note.run(), HttpResponseError);

        answer = async () => {
            throw new TypeError('network');
        };

        await assert.rejects(note.run(), TypeError);
    });

    test('turns a 401, 403, 404, 409 or 423 from validate() into refusal, as run() does, and throws nothing', async () => {
        const hook = () => useAction(createNote, { title: 'Hi', body: 'x' });

        render(hook).validate('title');
        await answerValidation(403, { message: 'You may not edit this note.' });

        assert.equal(validations[0][0], 'title');
        assert.equal(render(hook).refusal, 'You may not edit this note.');

        render(hook).validate({ only: ['title'] });

        assert.deepEqual(validations[1][0].only, ['title']);

        await answerValidation(404, '<html>');

        assert.equal(render(hook).refusal, '404');

        for (const [status, body, refusal] of [
            [401, '{"message":"Unauthenticated."}', 'Unauthenticated.'],
            [409, { message: 'That title is taken.' }, 'That title is taken.'],
            [423, '', '423'],
        ]) {
            render(hook).validate();
            await answerValidation(status, body);

            assert.equal(render(hook).refusal, refusal);
        }

        assert.deepEqual(calls, []);
    });

    test('clears the refusal when the next validation starts, and still calls the caller\'s own handlers', async () => {
        const seen = [];
        const hook = () => useAction(createNote, { title: 'Hi', body: 'x' });

        render(hook).validate('title', { onForbidden: (response) => seen.push(response.status), onStart: () => seen.push('start') });
        await answerValidation(403, { message: 'You may not edit this note.' });

        assert.deepEqual(seen, ['start', 403]);
        assert.equal(render(hook).refusal, 'You may not edit this note.');

        render(hook).validate('body');
        validations.at(-1)[1].onStart();

        assert.equal(render(hook).refusal, null);
    });

    test('leaves a 422 and a 5xx from validate() to Precognition', () => {
        render(() => useAction(createNote, { title: 'Hi', body: 'x' })).validate('title');

        const [, config] = validations[0];

        // Precognition picks a handler by status (401, 403, 404, 409, 422, 423) and rejects every other one. useAction adds
        // the five refusing handlers and onStart only, never onValidationError, and nothing else a 5xx could reach.
        assert.deepEqual(Object.keys(config).sort(), ['onConflict', 'onForbidden', 'onLocked', 'onNotFound', 'onStart', 'onUnauthorized']);
    });

    test("reads a field error on a key the form does not hold as a refusal, and a nested field's dot key as the field's", () => {
        const data = { title: '', author: { name: '' }, tags: [''], items: [{ name: 'a' }, { name: 'b' }, { name: '' }] };
        // Laravel keys a nested or list field's error by its dot path, and useHttp keeps each key as the server sent it.
        const refusals = Object.fromEntries(['post', 'post.status', 'title', 'author.name', 'tags.0', 'items.2.name'].map((key) => {
            errors = { [key]: `An error on ${key}.` };

            return [key, render(() => useAction(createNote, data)).refusal];
        }));

        assert.deepEqual(refusals, {
            post: 'An error on post.',
            'post.status': 'An error on post.status.',
            title: null,
            'author.name': null,
            'tags.0': null,
            'items.2.name': null,
        });
    });
});

/** A data-action part as the server sends it. */
const row = (id, data) => ({ type: 'data-action', id, data: { action: 'create-post', label: 'Saving…', effect: 'write', ...data } });

describe('useActionSync()', () => {
    beforeEach(() => {
        mock.timers.enable({ apis: ['setTimeout'] });
    });

    afterEach(() => {
        mock.timers.reset();
    });

    test('reads the parts an actionsChat() Chat emits and reloads the touched props through Inertia', () => {
        render(() => useActionSync());

        actionParts.emit(row('a:1', { status: 'running' }));
        actionParts.emit(row('a:1', { status: 'done', touches: ['posts'] }));
        actionParts.emit(row('a:2', { status: 'done', touches: ['stats'] }));

        assert.deepEqual(calls, []);

        mock.timers.tick(150);

        assert.deepEqual(calls.map(([name, options]) => (name === 'reload' ? [name, options.only, typeof options.onFinish] : [name, options])), [
            ['flushByCacheTags', ['posts', 'stats']],
            ['reload', ['posts', 'stats'], 'function'],
        ]);
    });

    test("with when: 'turn', applies only when the bus says the turn ended", () => {
        render(() => useActionSync({ when: 'turn' }));

        actionParts.emit(row('a:1', { status: 'done', touches: ['posts'] }));
        mock.timers.tick(1000);

        assert.deepEqual(calls, []);

        actionParts.end();
        mock.timers.tick(150);

        assert.deepEqual(calls.map(([name]) => name), ['flushByCacheTags', 'reload']);
    });

    test('blocked: true sets waiting and makes no request until apply()', () => {
        const hook = () => useActionSync({ blocked: true });
        const sync = render(hook);

        assert.equal(sync.waiting, false);

        sync.onPart(row('a:1', { status: 'done', touches: ['posts'] }));
        mock.timers.tick(1000);

        assert.equal(render(hook).waiting, true);
        assert.deepEqual(calls, []);

        render(hook).apply();

        assert.equal(render(hook).waiting, false);
        assert.deepEqual(calls.map(([name]) => name), ['flushByCacheTags', 'reload']);
    });

    test('reads blocked at the moment the refresh is due', () => {
        let blocked = false;
        const hook = () => useActionSync({ blocked });

        render(hook).touch(['posts']);
        blocked = true;
        render(hook);
        mock.timers.tick(150);

        assert.deepEqual(calls, []);
        assert.equal(render(hook).waiting, true);
    });

    test('runs a held refresh once by itself, 150 ms after the editor turns clean, and the Refresh prompt goes then', () => {
        let dirty = true;
        const hook = () => {
            useActionEdits(dirty);

            return useActionSync();
        };

        render(hook);
        actionParts.emit(row('a:1', { status: 'done', touches: ['posts'] }));
        mock.timers.tick(150);

        assert.equal(render(hook).waiting, true);
        assert.deepEqual(calls, []);

        dirty = false;
        render(hook);

        assert.equal(render(hook).waiting, true);

        mock.timers.tick(150);

        assert.deepEqual(calls.map(([name, options]) => (name === 'reload' ? [name, options.only] : [name, options])), [
            ['flushByCacheTags', ['posts']],
            ['reload', ['posts']],
        ]);
        assert.equal(render(hook).waiting, false);

        mock.timers.tick(10_000);

        assert.equal(calls.length, 2);
    });

    test('runs a held refresh by itself once blocked turns false', () => {
        let blocked = true;
        const hook = () => useActionSync({ blocked });

        render(hook).touch(['posts']);
        mock.timers.tick(150);

        assert.equal(render(hook).waiting, true);

        blocked = false;
        render(hook);
        mock.timers.tick(150);

        assert.deepEqual(calls.map(([name]) => name), ['flushByCacheTags', 'reload']);
        assert.equal(render(hook).waiting, false);
    });

    test('with resumeWhenClean: false, keeps the refresh held until apply()', () => {
        let dirty = true;
        let blocked = true;
        const hook = () => {
            useActionEdits(dirty);

            return useActionSync({ blocked, resumeWhenClean: false });
        };

        render(hook);
        actionParts.emit(row('a:1', { status: 'done', touches: ['posts'] }));
        mock.timers.tick(150);
        dirty = false;
        blocked = false;
        render(hook);
        mock.timers.tick(10_000);

        assert.equal(render(hook).waiting, true);
        assert.deepEqual(calls, []);

        render(hook).apply();

        assert.equal(render(hook).waiting, false);
        assert.deepEqual(calls.map(([name]) => name), ['flushByCacheTags', 'reload']);
    });

    test('takes an apply() of its own', () => {
        const requests = [];

        render(() => useActionSync({ how: 'remount', apply: (request) => requests.push(request) })).touch(['posts']);
        mock.timers.tick(150);

        assert.deepEqual(requests, [{ touches: ['posts'], follow: null, how: 'remount' }]);
        assert.deepEqual(calls, []);
    });

    test('stops listening when it unmounts', () => {
        render(() => useActionSync());
        unmount();

        actionParts.emit(row('a:1', { status: 'done', touches: ['posts'] }));
        mock.timers.tick(1000);

        assert.deepEqual(calls, []);
    });

    test('polls the feed from mount to unmount, and a remount (as StrictMode does) starts a fresh poller', async () => {
        const listeners = [];
        const bodies = [];
        const settle = () => new Promise((resolve) => setImmediate(resolve));
        const fetch = async (url, init) => {
            bodies.push(JSON.parse(init.body));

            return { status: 200, ok: true, json: async () => ({ now: bodies.length, touches: bodies.length === 2 ? ['posts'] : [] }) };
        };

        globalThis.location = { href: 'https://app.test/posts', origin: 'https://app.test' };
        globalThis.document = {
            hidden: false,
            cookie: '',
            addEventListener: (type, listener) => listeners.push(listener),
            removeEventListener: (type, listener) => listeners.splice(listeners.indexOf(listener), 1),
        };

        try {
            const hook = () => useActionSync({ feed: { url: '/actions/_changes', fetch } });

            render(hook);
            render(hook);
            await settle();

            assert.deepEqual(bodies, [{ since: null }]);

            mock.timers.tick(15_000);
            await settle();
            mock.timers.tick(150);

            assert.deepEqual(bodies, [{ since: null }, { since: 1 }]);
            assert.deepEqual(calls.map(([name, options]) => (name === 'reload' ? [name, options.only] : [name, options])), [
                ['flushByCacheTags', ['posts']],
                ['reload', ['posts']],
            ]);

            unmount();
            mock.timers.tick(60_000);
            await settle();

            assert.equal(listeners.length, 0);
            assert.equal(bodies.length, 2);

            render(hook);
            await settle();

            assert.deepEqual(bodies.at(-1), { since: null });
            assert.equal(listeners.length, 3);
        } finally {
            unmount();
            delete globalThis.document;
        }
    });
});

describe('useActionEdits()', () => {
    test('marks the editors store dirty while its flag is true, and its cleanup clears it', () => {
        let dirty = false;
        const hook = () => useActionEdits(dirty);

        render(hook);
        assert.equal(editors.dirty(), false);

        dirty = true;
        render(hook);
        assert.equal(editors.dirty(), true);

        dirty = false;
        render(hook);
        assert.equal(editors.dirty(), false);

        dirty = true;
        render(hook);
        unmount();
        assert.equal(editors.dirty(), false);
    });
});

describe('<ActionActivity>', () => {
    const markup = (rows) => renderToStaticMarkup(React.createElement(ActionActivity, { rows }));

    test('renders nothing for no rows', () => {
        assert.equal(markup([]), '');
    });

    test('renders an accessible list: labels in <bdi>, the status and effect as attributes, the note', () => {
        const html = markup([
            { id: 'a:1', action: 'create-post', label: 'Draft saved', status: 'done', effect: 'write' },
            { id: 'a:2', action: 'create-post', label: 'جارٍ الحفظ…', status: 'refused', effect: 'write', note: 'لم يتم' },
            { id: 'a:3', action: 'CountDrafts', label: 'Counting your drafts…', status: 'running' },
        ]);

        assert.equal(
            html,
            '<ol aria-live="polite" data-agentic-activity="">'
                + '<li data-status="done" data-effect="write"><bdi>Draft saved</bdi></li>'
                + '<li data-status="refused" data-effect="write"><bdi>جارٍ الحفظ…</bdi><span data-note="">لم يتم</span></li>'
                + '<li data-status="running"><bdi>Counting your drafts…</bdi></li>'
                + '</ol>',
        );
    });
});
