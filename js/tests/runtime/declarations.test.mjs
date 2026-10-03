import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';

const ts = createRequire(import.meta.url)('typescript');
const dist = new URL('../../dist/', import.meta.url);

/** Each member the built declarations declare, as [file, name, kind], read the way a linter reads them. */
function members() {
    const found = [];

    for (const file of readdirSync(dist).filter((name) => name.endsWith('.d.ts'))) {
        const source = ts.createSourceFile(file, readFileSync(new URL(file, dist), 'utf8'), ts.ScriptTarget.Latest, true);
        const visit = (node) => {
            if (ts.isMethodSignature(node) || ts.isMethodDeclaration(node) || ts.isPropertySignature(node)) {
                found.push([file, node.name.getText(source), ts.isPropertySignature(node) ? 'property' : 'method']);
            }

            ts.forEachChild(node, visit);
        };

        visit(source);
    }

    return found;
}

/**
 * @typescript-eslint/unbound-method reports a member declared as a method when a host destructures it or passes it on,
 * and accepts a property whose type is a function. So the package declares no method at all.
 */
test('the declarations hold no method, so a host may destructure any member or pass it on unbound', () => {
    const all = members();
    const has = (file, names) => names.filter((name) => !all.some(([at, member, kind]) => at === file && member === name && kind === 'property'));

    assert.deepEqual(all.filter(([, , kind]) => kind === 'method'), []);
    assert.deepEqual(has('sync.d.ts', ['set', 'dirty', 'subscribe', 'onPart', 'touch', 'flush', 'apply', 'dispose']), []);
    assert.deepEqual(has('parts.d.ts', ['emit', 'end', 'subscribe']), []);
    assert.deepEqual(has('react.d.ts', ['waiting', 'apply', 'flush', 'onPart', 'touch', 'onAnswer']), []);
});
