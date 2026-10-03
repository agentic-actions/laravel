import assert from 'node:assert/strict';
import { afterEach, beforeEach, describe, mock, test } from 'node:test';

/** A stub Inertia router that records its calls; a visit or reload finishes when the test calls its onFinish. */
const calls = [];
const router = {
    flushAll: () => calls.push(['flushAll']),
    flushByCacheTags: (tags) => calls.push(['flushByCacheTags', tags]),
    reload: (options) => calls.push(['reload', options]),
    visit: (href, options) => calls.push(['visit', href, options]),
};

mock.module('@inertiajs/core', { namedExports: { router } });

const { createActionSync, editors, notifyTouched } = await import('../../dist/index.js');
const { inertiaApply, installInertiaReload, reloadTouched } = await import('../../dist/inertia.js');

/** Whether a promise has settled, without waiting for it. */
async function settled(promise) {
    const pending = Symbol('pending');

    return (await Promise.race([promise.then(() => true), new Promise((resolve) => setImmediate(() => resolve(pending)))])) !== pending;
}

/** The recorded calls with each options object's onFinish replaced by a marker, and preserveState by its type. */
const shape = () =>
    calls.map((call) =>
        call.map((value) => {
            if (typeof value !== 'object' || value === null || Array.isArray(value)) {
                return value;
            }

            const { onFinish, preserveState, ...rest } = value;

            return { ...rest, ...(onFinish ? { onFinish: 'fn' } : {}), ...(preserveState ? { preserveState: typeof preserveState } : {}) };
        }),
    );

/** Finish the last visit or reload the router was asked for. */
const finishLast = () => calls.at(-1).at(-1).onFinish();

beforeEach(() => {
    globalThis.location = { href: 'https://app.test/posts/42/edit?tab=body', origin: 'https://app.test' };
});

afterEach(() => {
    calls.length = 0;
    delete globalThis.location;
});

describe('inertiaApply()', () => {
    test("'*' flushes every prefetch and reloads all props, resolving on onFinish", async () => {
        const applied = inertiaApply({ touches: ['posts', '*'], follow: null, how: 'reload' });

        assert.deepEqual(shape(), [['flushAll'], ['reload', { onFinish: 'fn' }]]);
        assert.equal(await settled(applied), false);

        finishLast();

        assert.equal(await settled(applied), true);
    });

    test('other touches flush their tags and reload only those props', async () => {
        const applied = inertiaApply({ touches: ['posts', 'stats'], follow: null, how: 'reload' });

        assert.deepEqual(shape(), [
            ['flushByCacheTags', ['posts', 'stats']],
            ['reload', { only: ['posts', 'stats'], onFinish: 'fn' }],
        ]);

        finishLast();

        assert.equal(await settled(applied), true);
    });

    test('a followed link flushes the touches, then visits the link instead of reloading', async () => {
        const applied = inertiaApply({ touches: ['posts'], follow: 'https://app.test/posts/43/edit', how: 'reload' });

        assert.deepEqual(shape(), [
            ['flushByCacheTags', ['posts']],
            ['visit', 'https://app.test/posts/43/edit', { onFinish: 'fn' }],
        ]);

        finishLast();

        assert.equal(await settled(applied), true);
    });

    test("a followed link with '*' flushes everything first, and one with no touches flushes nothing", () => {
        inertiaApply({ touches: ['*'], follow: '/posts/43', how: 'remount' });
        inertiaApply({ touches: [], follow: '/posts/44', how: 'reload' });

        assert.deepEqual(shape(), [
            ['flushAll'],
            ['visit', '/posts/43', { onFinish: 'fn' }],
            ['visit', '/posts/44', { onFinish: 'fn' }],
        ]);
    });

    test("how: 'remount' revisits this page whatever the touches, keeping scroll and replacing history", async () => {
        const applied = inertiaApply({ touches: ['profile'], follow: null, how: 'remount' });

        assert.deepEqual(shape(), [
            ['flushByCacheTags', ['profile']],
            ['visit', 'https://app.test/posts/42/edit?tab=body', { preserveScroll: true, replace: true, onFinish: 'fn', preserveState: 'function' }],
        ]);

        finishLast();

        assert.equal(await settled(applied), true);
    });

    test("the remount visit's preserveState reads the editors store when Inertia calls it, so an editor dirtied during the visit keeps its input", () => {
        const key = Symbol('editor');

        inertiaApply({ touches: ['*'], follow: null, how: 'remount' });

        const { preserveState } = calls.at(-1).at(-1);

        assert.equal(preserveState(), false);

        editors.set(key, true);

        try {
            assert.equal(preserveState(), true);
        } finally {
            editors.set(key, false);
        }

        assert.equal(preserveState(), false);
    });

    test('nothing to do resolves at once', async () => {
        const applied = inertiaApply({ touches: [], follow: null, how: 'reload' });

        assert.deepEqual(calls, []);
        assert.equal(await settled(applied), true);
    });
});

describe('reloadTouched()', () => {
    test('passes onFinish to the reload, and calls it at once when there is nothing to reload', () => {
        let finished = 0;
        const onFinish = () => finished++;

        reloadTouched(['posts'], { onFinish });
        reloadTouched([], { onFinish });

        assert.deepEqual(shape(), [
            ['flushByCacheTags', ['posts']],
            ['reload', { only: ['posts'], onFinish: 'fn' }],
        ]);
        assert.equal(finished, 1);

        finishLast();

        assert.equal(finished, 2);
    });
});

describe('installInertiaReload()', () => {
    test('registers one handler however often it runs', () => {
        const uninstall = installInertiaReload();
        const again = installInertiaReload();

        notifyTouched(['posts']);

        assert.deepEqual(calls, [
            ['flushByCacheTags', ['posts']],
            ['reload', { only: ['posts'] }],
        ]);

        calls.length = 0;
        notifyTouched(['*']);

        assert.deepEqual(calls, [['flushAll'], ['reload', {}]]);

        uninstall();
        again();
        calls.length = 0;
        notifyTouched(['posts']);

        assert.deepEqual(calls, []);
    });

    test('registers again after an uninstall', () => {
        installInertiaReload()();
        const uninstall = installInertiaReload();

        notifyTouched(['posts']);
        uninstall();

        assert.equal(calls.filter(([name]) => name === 'reload').length, 1);
    });
});

describe('createActionSync() with inertiaApply', () => {
    beforeEach(() => {
        mock.timers.enable({ apis: ['setTimeout'] });
    });

    afterEach(() => {
        mock.timers.reset();
    });

    test('never reloads over a dirty editor, and keeps one visit in flight until Inertia finishes it', async () => {
        const key = Symbol('editor');
        const sync = createActionSync({ apply: inertiaApply });

        editors.set(key, true);

        try {
            sync.touch(['posts']);
            mock.timers.tick(10_000);

            assert.deepEqual(calls, []);
        } finally {
            editors.set(key, false);
        }

        sync.flush();
        mock.timers.tick(150);

        assert.deepEqual(shape().map(([name]) => name), ['flushByCacheTags', 'reload']);

        sync.touch(['stats']);
        mock.timers.tick(150);
        await new Promise((resolve) => setImmediate(resolve));

        assert.equal(calls.length, 2);

        calls[1][1].onFinish();
        await new Promise((resolve) => setImmediate(resolve));
        mock.timers.tick(150);

        assert.deepEqual(shape().slice(2), [
            ['flushByCacheTags', ['stats']],
            ['reload', { only: ['stats'], onFinish: 'fn' }],
        ]);
    });
});
