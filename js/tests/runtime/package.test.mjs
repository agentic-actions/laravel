import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';

const manifest = JSON.parse(await readFile(new URL('../../package.json', import.meta.url), 'utf8'));

/** The private workspace root at the repository root, which holds the client's build and test toolchain. */
const workspace = JSON.parse(await readFile(new URL('../../../package.json', import.meta.url), 'utf8'));

test('the client has no runtime dependencies', () => {
    assert.equal(manifest.dependencies, undefined);
});

test('the client declares no devDependencies, so an install from vendor/ adds no copies of its peers', () => {
    assert.equal(manifest.devDependencies, undefined);
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
    assert.match(workspace.devDependencies.ai, /^\d+\.\d+\.\d+$/);
});

test('the toolchain lives in a private workspace root whose one workspace is the client', () => {
    assert.equal(workspace.private, true);
    assert.deepEqual(workspace.workspaces, ['js']);
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
