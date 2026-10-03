import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { gzipSync } from 'node:zlib';

/**
 * React, Inertia, the root and /inertia are imports of an entry, not part of it, so they are not counted. /react: 0.1
 * set 3,072 bytes, and 0.5 raised it to 4,608 for <ElicitationForm>. /views: 0.9 set 1,536, its own entry because
 * /react had 11 bytes left.
 */
const BUDGETS = [
    ['react', 4608, 'the /react budget'],
    ['views', 1536, 'the /views budget'],
];

for (const [entry, budget, criteria] of BUDGETS) {
    test(`/${entry} stays under ${budget} bytes gzipped (${criteria})`, (context) => {
        const size = gzipSync(readFileSync(new URL(`../../dist/${entry}.js`, import.meta.url)), { level: 9 }).length;

        context.diagnostic(`dist/${entry}.js: ${size} bytes gzipped`);

        assert.ok(size < budget, `dist/${entry}.js is ${size} bytes gzipped`);
    });
}
