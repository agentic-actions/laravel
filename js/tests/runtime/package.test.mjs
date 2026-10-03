import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';

const manifest = JSON.parse(await readFile(new URL('../../package.json', import.meta.url), 'utf8'));

test('the client has no runtime dependencies', () => {
    assert.equal(manifest.dependencies, undefined);
});

test('every peer dependency is optional', () => {
    for (const name of Object.keys(manifest.peerDependencies)) {
        assert.equal(manifest.peerDependenciesMeta[name]?.optional, true, `${name} is not optional`);
    }
});

test('the five entries resolve to the built files', () => {
    for (const [entry, file] of [['.', 'index'], ['./inertia', 'inertia'], ['./react', 'react'], ['./ai-sdk', 'ai-sdk'], ['./views', 'views']]) {
        assert.deepEqual(manifest.exports[entry], {
            types: `./dist/${file}.d.ts`,
            import: `./dist/${file}.js`,
        });
    }
});

test('ai is an optional peer, pinned exactly for the package\'s own build and tests', () => {
    assert.equal(manifest.peerDependencies.ai, '^7.0');
    assert.equal(manifest.peerDependenciesMeta.ai?.optional, true);
    assert.match(manifest.devDependencies.ai, /^\d+\.\d+\.\d+$/);
});

test('/ai-sdk and /views export their functions only', async () => {
    for (const [entry, functions] of [
        ['ai-sdk', ['actionsChat', 'actionsTransport', 'answerElicitation', 'refusalMessage', 'turnOutcome']],
        ['views', ['ActionTable', 'formatCell', 'refreshView', 'viewsOf']],
    ]) {
        assert.deepEqual(Object.keys(await import(`@agentic-actions/client/${entry}`)).sort(), functions);
    }
});

test('only /react has side effects', () => {
    assert.deepEqual(manifest.sideEffects, ['./dist/react.js']);
});

test('the client ships only its built files as an ES module', () => {
    assert.equal(manifest.type, 'module');
    assert.deepEqual(manifest.files, ['dist']);
});
