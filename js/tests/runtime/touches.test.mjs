import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { action, notifyTouched, onTouched } from '../../dist/index.js';

const createNote = action({ name: 'create-note', method: 'post', url: '/actions/create-note', touches: ['notes'] });

describe('onTouched() and notifyTouched()', () => {
    test('calls handlers in registration order, until they unsubscribe', () => {
        const seen = [];
        const first = onTouched((touches, definition) => seen.push(['first', touches, definition.name]));
        const second = onTouched((touches) => seen.push(['second', touches]));

        notifyTouched(['notes'], createNote);
        first();
        notifyTouched(['posts'], createNote);
        second();
        notifyTouched(['tags'], createNote);

        assert.deepEqual(seen, [
            ['first', ['notes'], 'create-note'],
            ['second', ['notes']],
            ['second', ['posts']],
        ]);
    });

    test('a handler registered twice runs twice, and each registration unsubscribes on its own', () => {
        let runs = 0;
        const handler = () => runs++;
        const once = onTouched(handler);
        const twice = onTouched(handler);

        notifyTouched(['notes'], createNote);
        once();
        notifyTouched(['notes'], createNote);
        twice();
        notifyTouched(['notes'], createNote);

        assert.equal(runs, 3);
    });

    test('an empty list notifies nobody', () => {
        let runs = 0;
        const unsubscribe = onTouched(() => runs++);

        notifyTouched([], createNote);
        unsubscribe();

        assert.equal(runs, 0);
    });

    test('passes a null definition when the touches carry none, as a copilot row\'s do', () => {
        const seen = [];
        const unsubscribe = onTouched((touches, definition) => seen.push([touches, definition]));

        notifyTouched(['posts']);
        notifyTouched(['stats'], null);
        unsubscribe();

        assert.deepEqual(seen, [
            [['posts'], null],
            [['stats'], null],
        ]);
    });

    test('a throwing handler does not stop the next, and its error is rethrown asynchronously', () => {
        const queued = [];
        const original = globalThis.queueMicrotask;
        globalThis.queueMicrotask = (callback) => queued.push(callback);

        const seen = [];
        const failing = onTouched(() => {
            throw new Error('handler failed');
        });
        const next = onTouched(() => seen.push('next'));

        try {
            assert.doesNotThrow(() => notifyTouched(['notes'], createNote));
        } finally {
            globalThis.queueMicrotask = original;
            failing();
            next();
        }

        assert.deepEqual(seen, ['next']);
        assert.equal(queued.length, 1);
        assert.throws(() => queued[0](), /handler failed/);
    });
});
