import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { cp, mkdir, mkdtemp, readFile, rm, symlink, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { after, before, describe, test } from 'node:test';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../..', import.meta.url));

/** The workspace root's node_modules, where npm installs the client's toolchain. */
const modules = fileURLToPath(new URL('../../../node_modules', import.meta.url));
const tsc = join(modules, 'typescript', 'bin', 'tsc');

/** The modules of the npm root, which may import nothing outside themselves. */
const ROOT_MODULES = ['index', 'action', 'call', 'errors', 'parts', 'sync', 'tables', 'touches'];

/** The compiler options every isolated build shares; each case adds its module resolution. */
const OPTIONS = {
    target: 'ES2022',
    lib: ['ES2022', 'DOM', 'DOM.Iterable'],
    strict: true,
    noUncheckedIndexedAccess: true,
    skipLibCheck: true,
    isolatedModules: true,
    verbatimModuleSyntax: true,
    types: [],
    noEmit: true,
};

let scratch;

before(async () => {
    scratch = await mkdtemp(join(tmpdir(), 'agentic-actions-client-'));
});

after(async () => {
    await rm(scratch, { recursive: true, force: true });
});

/** Copy the named source files into a fresh directory with the given tsconfig, then type-check it there. */
async function build(name, files, compilerOptions, install = []) {
    const directory = join(scratch, name);

    await mkdir(join(directory, 'src'), { recursive: true });

    for (const file of files) {
        await cp(join(root, 'src', `${file}.ts`), join(directory, 'src', `${file}.ts`));
    }

    for (const dependency of install) {
        await mkdir(dirname(join(directory, 'node_modules', dependency)), { recursive: true });
        await symlink(join(modules, dependency), join(directory, 'node_modules', dependency), 'dir');
    }

    await writeFile(join(directory, 'package.json'), JSON.stringify({ name: 'isolated-build', private: true, type: 'module' }));
    await writeFile(
        join(directory, 'tsconfig.json'),
        JSON.stringify({ compilerOptions: { ...OPTIONS, ...compilerOptions }, files: files.map((file) => `src/${file}.ts`) }),
    );

    return spawnSync(process.execPath, [tsc, '-p', join(directory, 'tsconfig.json')], { encoding: 'utf8' });
}

/** A package and every dependency and required peer it pulls in, as installed in node_modules. */
async function closure(name, seen = new Set()) {
    if (seen.has(name)) {
        return seen;
    }

    seen.add(name);

    const manifest = JSON.parse(await readFile(join(modules, name, 'package.json'), 'utf8'));
    const optional = manifest.peerDependenciesMeta ?? {};

    for (const dependency of Object.keys({ ...manifest.dependencies, ...manifest.peerDependencies })) {
        if (optional[dependency]?.optional !== true) {
            await closure(dependency, seen);
        }
    }

    return seen;
}

/** Every module specifier a built file imports or re-exports. */
async function specifiers(file) {
    const source = await readFile(join(root, 'dist', `${file}.js`), 'utf8');

    return [...source.matchAll(/(?:^|\n)\s*(?:import|export)\b[^'"]*?\bfrom\s+['"]([^'"]+)['"]|(?:^|\n)\s*import\s+['"]([^'"]+)['"]/g)].map(
        (match) => match[1] ?? match[2],
    );
}

describe('the npm root', () => {
    test('builds with nothing installed, under Node16 resolution', async () => {
        const result = await build('root', ROOT_MODULES, { module: 'NodeNext', moduleResolution: 'NodeNext' });

        assert.equal(result.status, 0, result.stdout + result.stderr);
    });

    test('imports nothing but its own modules, each with a .js specifier', async () => {
        for (const file of ROOT_MODULES) {
            for (const specifier of await specifiers(file)) {
                assert.match(specifier, /^\.\/[a-z]+\.js$/, `dist/${file}.js imports ${specifier}`);
            }
        }
    });
});

describe('/inertia', () => {
    test('builds with the root and @inertiajs/core only, without React', async () => {
        const result = await build('inertia', [...ROOT_MODULES, 'inertia'], {
            module: 'ESNext',
            moduleResolution: 'Bundler',
            baseUrl: '.',
            paths: { '@agentic-actions/client': ['./src/index.ts'] },
        }, ['@inertiajs/core']);

        assert.equal(result.status, 0, result.stdout + result.stderr);
    });

    test('imports the root by its bare name, so every entry shares one touch registry', async () => {
        assert.deepEqual((await specifiers('inertia')).sort(), ['@agentic-actions/client', '@inertiajs/core']);
        assert.ok((await specifiers('react')).includes('@agentic-actions/client'));
        assert.ok((await specifiers('react')).includes('@agentic-actions/client/inertia'));
    });
});

describe('/ai-sdk', () => {
    test('builds with ai and its own dependency tree only: no Inertia, no React', async () => {
        const installed = [...(await closure('ai'))];

        for (const name of installed) {
            assert.doesNotMatch(name, /^(@inertiajs\/|react$|react-dom$|@types\/react)/, `ai pulls in ${name}`);
        }

        const result = await build('ai-sdk', [...ROOT_MODULES, 'ai-sdk'], {
            module: 'ESNext',
            moduleResolution: 'Bundler',
            baseUrl: '.',
            paths: { '@agentic-actions/client': ['./src/index.ts'] },
        }, installed);

        assert.equal(result.status, 0, result.stdout + result.stderr);
    });

    test('imports only ai and the root, by its bare name', async () => {
        assert.deepEqual((await specifiers('ai-sdk')).sort(), ['@agentic-actions/client', 'ai']);
    });
});

describe('/views', () => {
    test('builds with the root and React only: no Inertia, no ai', async () => {
        const result = await build('views', [...ROOT_MODULES, 'views'], {
            module: 'ESNext',
            moduleResolution: 'Bundler',
            baseUrl: '.',
            paths: { '@agentic-actions/client': ['./src/index.ts'] },
        }, ['react', ...(await closure('@types/react'))]);

        assert.equal(result.status, 0, result.stdout + result.stderr);
    });

    test('imports only react and the root, by its bare name', async () => {
        assert.deepEqual((await specifiers('views')).sort(), ['@agentic-actions/client', 'react']);
    });
});
