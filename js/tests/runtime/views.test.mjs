import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { afterEach, describe, mock, test } from 'node:test';
import { readUIMessageStream } from 'ai';

/*
 * Tables on the client. The root reads them without React: viewsOf() returns a message's data-view parts,
 * formatCell() writes a cell in the person's locale, and refreshView() posts to the group's _views route. /views
 * re-exports those, and <ActionTable> draws a view as text, the workbench's recorded turn included.
 */

// Behind UTC all year, so a date read as local midnight would fall on the day before.
process.env.TZ = 'America/New_York';

// The real React and its server renderer, loaded before 'react' is mocked. Rendered by the server renderer, the table
// runs React's own useState; called by hand for its Refresh button, it runs the small state below, kept across calls.
const require = createRequire(import.meta.url);
const React = require('react');
const { renderToStaticMarkup } = require('react-dom/server');

let manual = false;
let slots = [];
let cursor = 0;

const useState = (initial) => {
    if (!manual) {
        return React.useState(initial);
    }

    const slot = (slots[cursor++] ??= { value: initial });

    return [slot.value, (value) => (slot.value = value)];
};

mock.module('react', { namedExports: { createElement: React.createElement, useState } });

const { ActionFailedError, ActionRefusedError, formatCell, refreshView, viewsOf } = await import('../../dist/index.js');
const views = await import('../../dist/views.js');
const { ActionTable } = views;

/** A ref as Laravel writes a ULID key: lower case. */
const REF = '01jz3k8m9n0p1q2r3s4t5v6w7x';

const TABLE = {
    columns: [
        { key: 'title', label: 'Post', type: 'text' },
        { key: 'words', label: 'Words', type: 'integer', description: 'Words in the body' },
        { key: 'share', label: 'Share', type: 'percent' },
        { key: 'published', label: 'Published', type: 'date' },
        { key: 'featured', label: 'Featured', type: 'boolean' },
    ],
    rows: [
        { title: 'Launch notes', words: 1234, share: 0.25, published: '2026-09-30', featured: true },
        { title: 'Roadmap', words: 87, share: 0.0725, published: null, featured: false },
    ],
    truncated: false,
    chart: { type: 'bar', x: 'title', y: ['words'] },
    caption: 'Words per post',
};

const VIEW = {
    action: 'post-stats',
    table: TABLE,
    at: '2026-09-30T14:05:00+03:00',
    ref: REF,
    labels: { refresh: 'Refresh', truncated: 'Showing the first :count rows' },
};

/** The cells of VIEW's rows as the table writes them in English. */
const CELLS = ['Launch notes', '1,234', '25%', 'Sep 30, 2026', '✓', 'Roadmap', '87', '7.25%', '', ''];

/** A column of the given type, as the server declares it. */
const column = (type, extra = {}) => ({ key: 'value', label: 'Value', type, ...extra });

const realFetch = globalThis.fetch;

/** Replace fetch with one that records each call and answers with the given status and body. */
function stubFetch(status = 200, body = JSON.stringify({ table: TABLE, at: '2026-10-01T09:00:00+00:00' })) {
    const calls = [];

    globalThis.fetch = async (url, init) => {
        calls.push({ url, init });

        return new Response(body, { status, headers: { 'Content-Type': 'application/json' } });
    };

    return calls;
}

/** Run a page in Node: a location and a document with an XSRF cookie and the given lang. */
function page(lang = '') {
    globalThis.location = { href: 'https://app.test/posts', origin: 'https://app.test' };
    globalThis.document = { cookie: 'XSRF-TOKEN=token%3D%3D', documentElement: { lang } };
}

afterEach(() => {
    globalThis.fetch = realFetch;
    manual = false;
    delete globalThis.location;
    delete globalThis.document;
});

/** The markup the server renderer writes for these props. */
const html = (props) => renderToStaticMarkup(React.createElement(ActionTable, props));

/** Each header and data cell of the markup, in order, as its tag, attributes and text. */
function cells(markup) {
    return [...markup.matchAll(/<(th|td)\b([^>]*)>(.*?)<\/\1>/g)].map(([, tag, attributes, text]) => ({
        tag,
        ...Object.fromEntries([...attributes.matchAll(/([\w-]+)="([^"]*)"/g)].map(([, name, value]) => [name, value])),
        text,
    }));
}

describe('viewsOf()', () => {
    test("returns a message's data-view parts in order, each as its data and id, and leaves out one without a string id or a table's columns and rows", () => {
        const second = { ...VIEW, ref: undefined, table: { ...TABLE, caption: null } };
        const message = {
            parts: [
                { type: 'text', text: 'Here are the posts.' },
                { type: 'data-view', id: 'view:call_1', data: VIEW },
                { type: 'data-action', id: 'call_1', data: { action: 'post-stats', label: 'Read', status: 'done' } },
                { type: 'data-view', id: 'view:call_2', data: { ...VIEW, table: { ...TABLE, rows: undefined } } },
                { type: 'data-view', id: 'view:call_3', data: { ...VIEW, table: { ...TABLE, columns: 'title' } } },
                { type: 'data-view', id: 'view:call_4' },
                { type: 'data-view', id: 5, data: VIEW },
                { type: 'data-view', id: 'view:call_6', data: second },
            ],
        };

        assert.deepEqual(viewsOf(message), [
            { ...VIEW, id: 'view:call_1' },
            { ...second, id: 'view:call_6' },
        ]);
    });
});

describe('formatCell()', () => {
    test('writes each type in the locale: numbers to their decimals, a percent from a fraction, money in its currency, true as ✓', () => {
        for (const [value, cell, expected] of [
            [1234, column('integer'), '1,234'],
            [1234.567, column('number'), '1,234.57'],
            [1234.5, column('number', { decimals: 0 }), '1,235'],
            [0.25, column('percent'), '25%'],
            [0.0725, column('percent'), '7.25%'],
            [1234.5, column('money', { currency: 'USD' }), '$1,234.50'],
            [1234.5, column('money', { currency: 'JPY' }), '¥1,235'],
            [true, column('boolean'), '✓'],
            [false, column('boolean'), ''],
            ['Launch notes', column('text'), 'Launch notes'],
            [null, column('integer'), ''],
            [undefined, column('date'), ''],
        ]) {
            assert.equal(formatCell(value, cell, 'en'), expected, `${String(value)} as ${cell.type}`);
        }

        assert.equal(formatCell(1234, column('integer'), 'ar-EG'), '١٬٢٣٤');
    });

    test("writes a date as the day it names in any time zone, and a datetime in the person's zone", () => {
        assert.equal(formatCell('2026-09-30', column('date'), 'en'), 'Sep 30, 2026');
        assert.match(formatCell('2026-09-30T02:30:00+00:00', column('datetime'), 'en'), /^Sep 29, 2026, 10:30\sPM$/);
    });

    test("falls back to the page's lang, then 'en', and reads a malformed locale or lang as 'en'", () => {
        const integer = column('integer');

        assert.equal(formatCell(1234, integer), '1,234');

        page('ar-EG');
        assert.equal(formatCell(1234, integer), '١٬٢٣٤');
        assert.equal(formatCell(1234, integer, 'en'), '1,234');
        assert.equal(formatCell(1234, integer, 'ar_EG'), '1,234');

        page('en_US');
        assert.equal(formatCell(1234, integer), '1,234');
        assert.equal(formatCell('2026-09-30', column('date')), 'Sep 30, 2026');
    });

    test('writes a value Intl refuses as itself, so a cell never throws', () => {
        // The server keeps a date that starts like one; this one names no day.
        assert.equal(formatCell('2026-99-99', column('date'), 'ar-EG'), '2026-99-99');
        assert.equal(formatCell(12, column('money'), 'en'), '12');
    });
});

describe('refreshView()', () => {
    test('refuses a ref that is not a ULID before any request', async () => {
        const calls = stubFetch();

        for (const ref of ['', '..', 'view:call_1', REF.slice(1), `${REF}0`, `${REF.slice(0, 25)}u`, `${REF.slice(0, 25)}/`, `${REF.slice(0, 23)}%2F`]) {
            await assert.rejects(refreshView('/actions/_views', ref), { message: 'The view ref must be a ULID.' }, JSON.stringify(ref));
        }

        assert.equal(calls.length, 0);
    });

    test('posts JSON to the ref under the url, with the signal, and resolves the fresh table and its time', async () => {
        const calls = stubFetch();
        const signal = new AbortController().signal;

        assert.deepEqual(await refreshView('/teams/acme/actions/_views', REF, { signal }), { table: TABLE, at: '2026-10-01T09:00:00+00:00' });
        assert.deepEqual(await refreshView('/actions/_views', REF.toUpperCase()), { table: TABLE, at: '2026-10-01T09:00:00+00:00' });

        const [{ url, init }, upper] = calls;

        assert.equal(url, `/teams/acme/actions/_views/${REF}`);
        assert.equal(upper.url, `/actions/_views/${REF.toUpperCase()}`);
        assert.equal(init.method, 'POST');
        assert.equal(init.body, '{}');
        assert.equal(init.headers['Content-Type'], 'application/json');
        assert.equal(init.headers.Accept, 'application/json');
        assert.equal(init.signal, signal);
    });

    test("sends the XSRF header to the page's own origin only", async () => {
        page();
        const calls = stubFetch();

        await refreshView('/teams/acme/actions/_views', REF);
        await refreshView('https://app.test/actions/_views', REF);
        await refreshView('https://api.other.test/actions/_views', REF);
        // Only the signal is read from the options, so a caller cannot send the token further.
        await refreshView('https://api.other.test/actions/_views', REF, { credentials: 'include' });

        assert.deepEqual(
            calls.map(({ init }) => init.headers['X-XSRF-TOKEN']),
            ['token==', 'token==', undefined, undefined],
        );
        assert.equal(calls[3].init.credentials, 'same-origin');
    });

    test("rejects with the package's error classes and the server's sentence", async () => {
        for (const [status, message, type] of [
            [404, 'Not found.', ActionRefusedError],
            [422, 'The given data was invalid.', ActionRefusedError],
            [500, 'Server Error', ActionFailedError],
        ]) {
            stubFetch(status, JSON.stringify({ message }));

            await assert.rejects(refreshView('/actions/_views', REF), (error) => error instanceof type && error.status === status && error.message === message);
        }
    });

    test('rejects a success that holds no table, or no time, with ActionFailedError', async () => {
        for (const body of ['{"ok":true}', 'null', '', JSON.stringify({ table: TABLE }), JSON.stringify({ table: { columns: [] }, at: '2026-10-01T09:00:00+00:00' })]) {
            stubFetch(200, body);

            await assert.rejects(refreshView('/actions/_views', REF), (error) => error instanceof ActionFailedError && error.status === 200, body);
        }
    });
});

describe('/views', () => {
    test("re-exports the root's viewsOf(), formatCell() and refreshView(), so either import reaches the same function", () => {
        assert.equal(views.viewsOf, viewsOf);
        assert.equal(views.formatCell, formatCell);
        assert.equal(views.refreshView, refreshView);
    });
});

describe('<ActionTable>', () => {
    test('draws a plain table: its caption, a header per column, each cell in the locale, numbers at the end, each part with its class', () => {
        const classNames = { table: 't', caption: 'c', head: 'h', cell: 'd', number: 'n', note: 'o', refresh: 'r' };
        const markup = html({ view: VIEW, locale: 'en', classNames });

        assert.match(markup, /^<div data-agentic-view=""><table class="t"><caption class="c">Words per post<\/caption><thead><tr><th/);
        assert.deepEqual(cells(markup), [
            { tag: 'th', scope: 'col', class: 'h', text: 'Post' },
            { tag: 'th', scope: 'col', title: 'Words in the body', class: 'h', style: 'text-align:end', text: 'Words' },
            { tag: 'th', scope: 'col', class: 'h', style: 'text-align:end', text: 'Share' },
            { tag: 'th', scope: 'col', class: 'h', text: 'Published' },
            { tag: 'th', scope: 'col', class: 'h', text: 'Featured' },
            { tag: 'td', class: 'd', text: 'Launch notes' },
            { tag: 'td', class: 'n', style: 'text-align:end', text: '1,234' },
            { tag: 'td', class: 'n', style: 'text-align:end', text: '25%' },
            { tag: 'td', class: 'd', text: 'Sep 30, 2026' },
            { tag: 'td', class: 'd', text: '✓' },
            { tag: 'td', class: 'd', text: 'Roadmap' },
            { tag: 'td', class: 'n', style: 'text-align:end', text: '87' },
            { tag: 'td', class: 'n', style: 'text-align:end', text: '7.25%' },
            { tag: 'td', class: 'd', text: '' },
            { tag: 'td', class: 'd', text: '' },
        ]);
        assert.match(markup, /<time dateTime="2026-09-30T14:05:00\+03:00">Sep 30, 2026, 7:05\sAM<\/time>/);
        assert.doesNotMatch(html({ view: { ...VIEW, table: { ...TABLE, caption: null } } }), /<caption/);
    });

    test("writes its cells in the given locale, and follows the page's direction: no dir of its own, numbers at the end", () => {
        const markup = html({ view: VIEW, locale: 'ar-EG' });

        assert.equal(cells(markup).find((cell) => cell.tag === 'td' && cell.style !== undefined)?.text, '١٬٢٣٤');
        assert.doesNotMatch(markup, /\bdir=/);
        assert.doesNotMatch(markup, /text-align:(left|right)/);
    });

    test('renders every label and cell as text, never as HTML', () => {
        const hostile = '<img src=x onerror=alert(1)>';
        const view = {
            ...VIEW,
            table: { ...TABLE, caption: hostile, columns: [{ key: 'title', label: hostile, type: 'text' }], rows: [{ title: hostile }], truncated: true },
            labels: { refresh: hostile, truncated: hostile },
        };
        const markup = html({ view, refreshUrl: '/actions/_views' });

        assert.doesNotMatch(markup, /<img/);
        // The caption, the header, the cell, the note and the button.
        assert.equal(markup.split('&lt;img src=x onerror=alert(1)&gt;').length - 1, 5);
    });

    test('shows the truncated note only for a truncated table, and Refresh only with both a ref and a refreshUrl', () => {
        const truncated = { ...VIEW, table: { ...TABLE, truncated: true } };
        const button = /<button type="button" class="r">Refresh<\/button>/;
        const classNames = { note: 'o', refresh: 'r' };

        assert.match(html({ view: truncated, classNames }), /<p class="o">Showing the first 2 rows<\/p>/);
        assert.doesNotMatch(html({ view: VIEW, classNames }), /<p/);
        assert.doesNotMatch(html({ view: { ...truncated, labels: { refresh: 'Refresh' } }, classNames }), /<p/);
        assert.match(html({ view: VIEW, refreshUrl: '/actions/_views', classNames }), button);
        assert.doesNotMatch(html({ view: VIEW, classNames }), /<button/);
        assert.doesNotMatch(html({ view: { ...VIEW, ref: undefined }, refreshUrl: '/actions/_views', classNames }), /<button/);
    });

    test("fills the truncated note's :count with the rows the table holds, in the cells' locale", () => {
        const table = { ...TABLE, columns: [TABLE.columns[0]], rows: Array.from({ length: 1000 }, (_, index) => ({ title: `Post ${index}` })), truncated: true };
        const note = (labels, locale) => html({ view: { ...VIEW, table, labels }, locale }).match(/<p>(.*?)<\/p>/)[1];

        assert.equal(note(VIEW.labels, 'en'), 'Showing the first 1,000 rows');
        assert.equal(note({ refresh: 'تحديث', truncated: 'عرض أول :count صف' }, 'ar-EG'), 'عرض أول ١٬٠٠٠ صف');
    });

    test('Refresh is disabled while it runs, then shows the fresh table, its time and its note, and hands them to onRefresh', async () => {
        const refreshed = [];
        const render = mount({ onRefresh: (fresh) => refreshed.push(fresh) });
        const calls = holdFetch();
        const changelog = { title: 'Changelog', words: 42, share: 0.5, published: '2026-10-01', featured: false };
        const table = { ...TABLE, rows: [{ ...TABLE.rows[0], words: 1500 }, TABLE.rows[1], changelog], truncated: true };

        assert.deepEqual(render().shown, { cells: CELLS, at: VIEW.at, note: undefined, disabled: false });

        await render().click();

        assert.equal(calls.length, 1);
        assert.equal(calls[0].url, `/teams/acme/actions/_views/${REF}`);
        assert.deepEqual(render().shown, { cells: CELLS, at: VIEW.at, note: undefined, disabled: true });
        assert.deepEqual(refreshed, []);

        calls[0].answer(200, JSON.stringify({ table, at: '2026-10-01T09:00:00+00:00' }));
        await settle();

        assert.deepEqual(render().shown, {
            cells: ['Launch notes', '1,500', ...CELLS.slice(2, 10), 'Changelog', '42', '50%', 'Oct 1, 2026', ''],
            at: '2026-10-01T09:00:00+00:00',
            note: 'Showing the first 3 rows',
            disabled: false,
        });
        assert.deepEqual(refreshed, [{ table, at: '2026-10-01T09:00:00+00:00' }]);
    });

    test('a failed Refresh keeps the table and its time, enables the button again, calls no onRefresh, and throws nothing', async () => {
        for (const [name, fail] of [
            ['a 500', (call) => call.answer(500, '{"message":"Server Error"}')],
            ['a success with no table', (call) => call.answer(200, '{"ok":true}')],
            ['a network error', (call) => call.fail()],
        ]) {
            const refreshed = [];
            const render = mount({ onRefresh: (fresh) => refreshed.push(fresh) });
            const calls = holdFetch();

            await render().click();
            assert.equal(render().shown.disabled, true, name);

            fail(calls[0]);
            await settle();

            assert.deepEqual(render().shown, { cells: CELLS, at: VIEW.at, note: undefined, disabled: false }, name);
            assert.deepEqual(refreshed, [], name);
        }
    });
});

/** The workbench's recorded turn, in which the copilot shows a table action's rows and a dataset's (StreamFixturesTest writes it). */
const recorded = new URL('../fixtures/streams/views.sse', import.meta.url);

describe("the workbench's recorded turn", () => {
    test('draws each table the turn showed, as the page does from the message ai 7 builds, with Refresh', async () => {
        const parts = readFileSync(recorded, 'utf8')
            .split('\n\n')
            .filter((frame) => frame.startsWith('data: ') && frame !== 'data: [DONE]')
            .map((frame) => JSON.parse(frame.slice('data: '.length)));
        let message;

        for await (const snapshot of readUIMessageStream({ stream: new ReadableStream({ start: (controller) => (parts.forEach((part) => controller.enqueue(part)), controller.close()) }) })) {
            message = snapshot;
        }

        const shown = viewsOf(message);

        assert.deepEqual(shown.map((view) => [view.id, view.action]), [['view:call_1', 'post-stats'], ['view:call_2', 'posts']]);
        assert.deepEqual(shown.map((view) => cells(html({ view, locale: 'en' })).map((cell) => cell.text)), [
            ['Title', 'Words', 'Share', 'Launch notes', '3', '75%', 'Roadmap', '1', '25%'],
            ['Team', 'Posts', 'Published', 'Blue', '1', '1', '', '1', '0'],
        ]);
        assert.match(html({ view: shown[1], locale: 'en' }), /<caption>Posts, Published by Team · 1 Sep 2026 – 30 Sep 2026 · the 50 highest by Posts<\/caption>/);
        assert.match(html({ view: shown[0], refreshUrl: '/actions/_views', locale: 'en' }), /<button type="button">Refresh<\/button>/);
    });
});

/**
 * Mount the table by hand: each render() calls it with the small state above, and gives what it shows (the rows'
 * cells, the time, the note and whether Refresh is disabled) and click(), which presses Refresh and lets it settle.
 */
function mount(props) {
    manual = true;
    slots = [];

    return () => {
        cursor = 0;

        const tree = ActionTable({ view: VIEW, refreshUrl: '/teams/acme/actions/_views', locale: 'en', ...props });
        const markup = renderToStaticMarkup(tree);
        const button = find(tree, (element) => element.type === 'button');

        return {
            shown: {
                cells: cells(markup).filter((cell) => cell.tag === 'td').map((cell) => cell.text),
                at: markup.match(/<time dateTime="(.+?)">/)[1],
                note: markup.match(/<p>(.*?)<\/p>/)?.[1],
                disabled: button.props.disabled,
            },
            click: async () => {
                button.props.onClick();
                await settle();
            },
        };
    };
}

/** Replace fetch with one whose calls wait: each is answered later by its answer(status, body), or fails by its fail(). */
function holdFetch() {
    const calls = [];

    globalThis.fetch = (url, init) =>
        new Promise((resolve, reject) => {
            calls.push({
                url,
                init,
                answer: (status, body) => resolve(new Response(body, { status, headers: { 'Content-Type': 'application/json' } })),
                fail: () => reject(new TypeError('fetch failed')),
            });
        });

    return calls;
}

/** Let a refresh's answer and the handlers after it run. */
async function settle() {
    for (let tick = 0; tick < 5; tick++) {
        await new Promise(setImmediate);
    }
}

/** Each element of a tree, depth first. */
function* elements(node) {
    if (Array.isArray(node)) {
        for (const child of node) {
            yield* elements(child);
        }
    } else if (node !== null && typeof node === 'object' && 'props' in node) {
        yield node;
        yield* elements(node.props.children);
    }
}

const find = (tree, match) => [...elements(tree)].find(match);
