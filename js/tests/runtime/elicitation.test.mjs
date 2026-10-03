import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { afterEach, describe, mock, test } from 'node:test';
import { AbstractChat, lastAssistantMessageIsCompleteWithApprovalResponses, readUIMessageStream } from 'ai';

/*
 * Forms on the client: elicitation() finds the form a message still waits on, <ElicitationForm> renders any
 * MCP form-mode requestedSchema and answers with an ElicitResult, and /ai-sdk checks an accept by Precognition, then
 * sends the answer on the answered tool part, once.
 */

// UTC+3 all year, so a date-time default opens at a known local time.
process.env.TZ = 'Asia/Riyadh';

// The real React and its server renderer, loaded before 'react' is mocked. Rendered by the server renderer, the form
// runs React's own hooks; called by hand for its events, it runs the small hook runtime below.
const require = createRequire(import.meta.url);
const React = require('react');
const { renderToStaticMarkup } = require('react-dom/server');

let manual = false;
let slots = [];
let cursor = 0;
let effects = [];
let current = null;

const slot = (initial) => {
    const index = cursor++;

    slots[index] ??= initial();

    return slots[index];
};

const hooks = {
    useId: () => ':r1:',
    useRef: (initial) => slot(() => ({ current: initial })),
    useState: (initial) => {
        const state = slot(() => ({ value: initial }));

        return [state.value, (value) => (state.value = value)];
    },
    useEffect: (effect, deps) => {
        const state = slot(() => ({ deps: undefined }));

        if (state.deps === undefined || deps.some((dep, position) => !Object.is(dep, state.deps[position]))) {
            effects.push(() => {
                state.deps = deps;
                effect();
            });
        }
    },
};

const delegate = (name) => (...args) => (manual ? hooks[name](...args) : React[name](...args));

mock.module('react', {
    namedExports: { createElement: React.createElement, useEffect: delegate('useEffect'), useId: delegate('useId'), useRef: delegate('useRef'), useState: delegate('useState') },
});
mock.module('@inertiajs/core', { namedExports: { router: { reload() {}, visit() {}, flushAll() {}, flushByCacheTags() {} }, HttpResponseError: class extends Error {} } });
mock.module('@inertiajs/react', { namedExports: { Link: ({ children }) => children, useHttp: () => ({}) } });

const { actionRows, approvalCard, elicitation } = await import('../../dist/index.js');
const { ElicitationForm } = await import('../../dist/react.js');
const { actionsChat, actionsTransport, answerElicitation } = await import('../../dist/ai-sdk.js');

/** 7.1's form, as the server builds it. */
const PARAMS = {
    mode: 'form',
    message: 'A few details for your post.',
    requestedSchema: {
        type: 'object',
        properties: {
            title: { type: 'string', title: 'Title', minLength: 1, maxLength: 120, default: 'Launch notes' },
            body: { type: 'string', title: 'Body', maxLength: 5000, 'x-agentic-actions': { widget: 'textarea' } },
            status: { type: 'string', title: 'Status', oneOf: [{ const: 'draft', title: 'Draft' }, { const: 'published', title: 'Published' }] },
        },
        required: ['title', 'body', 'status'],
    },
};

const LABELS = { source: 'Asked by Laravel', submit: 'Submit', decline: 'Decline', cancel: 'Not now' };

const FORM = { action: 'draft-team-post', params: PARAMS, labels: LABELS };

/** Every primitive of the standard, with and without titles, defaults and bounds. */
const EVERY = {
    message: 'Everything a form can hold.',
    requestedSchema: {
        type: 'object',
        properties: {
            name: { type: 'string', title: 'Name', description: 'As on your profile.', minLength: 2, maxLength: 40 },
            email: { type: 'string', title: 'Email', format: 'email' },
            site: { type: 'string', title: 'Site', format: 'uri' },
            day: { type: 'string', title: 'Day', format: 'date', default: '2026-10-01' },
            at: { type: 'string', title: 'At', format: 'date-time', default: '2026-09-26T07:30:00Z' },
            later: { type: 'string', title: 'Later', format: 'date-time', default: '2026-09-26T09:30:00+02:00' },
            never: { type: 'string', title: 'Never', format: 'date-time', default: 'next Tuesday' },
            notes: { type: 'string', 'x-agentic-actions': { widget: 'textarea' } },
            count: { type: 'integer', title: 'Count', minimum: 1, maximum: 10, default: 3 },
            ratio: { type: 'number', title: 'Ratio', minimum: 0, maximum: 1 },
            share: { type: 'boolean', title: 'Share', default: true },
            notify: { type: 'boolean', title: 'Notify' },
            size: { type: 'string', title: 'Size', enum: ['s', 'm', 'l'] },
            status: { type: 'string', title: 'Status', oneOf: [{ const: 'draft', title: 'Draft' }, { const: 'published', title: 'Published' }], default: 'published' },
            legacy: { type: 'string', title: 'Legacy', enum: ['a', 'b'], enumNames: ['Alpha', 'Beta'] },
            tags: { type: 'array', title: 'Tags', items: { type: 'string', enum: ['news', 'blog'] }, minItems: 1, maxItems: 2 },
            owners: { type: 'array', title: 'Owners', items: { anyOf: [{ const: '5', title: 'Ada' }, { const: '7', title: 'Grace' }] }, default: ['7'] },
        },
        required: ['name', 'count', 'share', 'size', 'status', 'tags'],
    },
};

/** A tool part as useChat holds it, in the given state. */
const toolPart = (id, state, extra = {}) => ({ type: 'tool-draft-team-post', toolCallId: id, state, input: {}, approval: { id }, ...extra });

/** The form part for a call. */
const formPart = (id, data = FORM) => ({ type: 'data-elicitation', id: `elicitation:${id}`, data });

/** The server-rendered markup, with React's generated id read back as ID. */
function markup(props) {
    const html = renderToStaticMarkup(React.createElement(ElicitationForm, { params: PARAMS, labels: LABELS, onAnswer: () => {}, ...props }));
    const id = html.match(/aria-labelledby="(.+?)m"/)[1];

    return html.replaceAll(id, 'ID');
}

/** Each control named name in the markup, as its tag and attributes, in order. */
function controls(html, name) {
    return [...html.matchAll(new RegExp(`<(input|select|textarea)\\b[^>]*\\bname="${name}"[^>]*>`, 'g'))].map(([tag, type]) => ({
        tag: type,
        ...Object.fromEntries([...tag.matchAll(/([\w-]+)="([^"]*)"/g)].map(([, attribute, value]) => [attribute, value])),
    }));
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

/** The text of an element's subtree. */
const text = (node) => [...elements(node)].flatMap((element) => [element.props.children].flat().filter((child) => typeof child === 'string')).join('');

/** Mount the form by hand: each render() calls the component with the hook runtime above, then runs its effects. */
function mount(props) {
    manual = true;
    slots = [];

    return () => {
        cursor = 0;
        effects = [];
        current = ElicitationForm({ params: PARAMS, labels: LABELS, ...props });
        effects.forEach((run) => run());

        return current;
    };
}

/**
 * The browser's half of a submit: constraint validation (a required control with no value stops it, and no submit
 * event fires), then the successful controls as FormData lists them, after the person's edits. An edit is a control's
 * value, a boolean's checked state, or a list of the checked values of a group.
 */
function submit(tree, edits = {}) {
    const entries = [];

    for (const control of [...elements(tree)].filter((element) => ['input', 'select', 'textarea'].includes(element.type))) {
        const { name, type, value, defaultValue, defaultChecked, required, children } = control.props;

        if (type === 'checkbox') {
            const checked = name in edits ? (value === undefined ? edits[name] : edits[name].includes(value)) : defaultChecked === true;

            if (checked) {
                entries.push([name, value ?? 'on']);
            }

            continue;
        }

        const first = control.type === 'select' ? [...elements(children)][0].props.value : '';
        const entry = name in edits ? edits[name] : String(defaultValue ?? first);

        if (required && entry === '') {
            return false;
        }

        entries.push([name, entry]);
    }

    const target = { form: true };

    globalThis.FormData = class {
        constructor(form) {
            assert.equal(form, target);
        }

        getAll(key) {
            return entries.filter(([name]) => name === key).map(([, value]) => value);
        }
    };

    find(tree, (element) => element.type === 'form').props.onSubmit({ preventDefault: () => {}, currentTarget: target });

    return true;
}

/** The form's three buttons, by their labels. */
const buttons = (tree) => Object.fromEntries([...elements(tree)].filter((element) => element.type === 'button').map((button) => [button.props.children, button]));

const settle = () => new Promise((resolve) => setImmediate(resolve));

const FormData = globalThis.FormData;

afterEach(() => {
    manual = false;
    globalThis.FormData = FormData;
    delete globalThis.location;
    delete globalThis.document;
});

describe('elicitation()', () => {
    test('gives the form and the id the answer names for a tool part that still asks', () => {
        const message = { parts: [{ type: 'step-start' }, { type: 'text', text: 'Once you fill it in.' }, toolPart('call_1', 'approval-requested'), formPart('call_1')] };

        assert.deepEqual(elicitation(message), { ...FORM, id: 'call_1' });
    });

    test('reads a dynamic tool part too', () => {
        const message = { parts: [{ type: 'dynamic-tool', toolName: 'draft-team-post', toolCallId: 'call_1', state: 'approval-requested', input: {}, approval: { id: 'call_1' } }, formPart('call_1')] };

        assert.equal(elicitation(message)?.id, 'call_1');
    });

    test("skips a card's call and a host tool's, and approvalCard() skips a form's", () => {
        const card = { action: 'delete-post', effect: 'destructive', label: 'Waiting', title: 'Delete it?', summary: [], confirm: 'Confirm', decline: 'Decline' };
        const message = {
            parts: [
                toolPart('call_1', 'approval-requested', { type: 'tool-delete-post' }),
                { type: 'tool-publish', toolCallId: 'call_2', state: 'approval-requested', input: {}, approval: { id: 'call_2' } },
                toolPart('call_3', 'approval-requested'),
                { type: 'data-approval', id: 'approval:call_1', data: card },
                formPart('call_3'),
            ],
        };

        assert.equal(elicitation(message)?.id, 'call_3');
        assert.equal(approvalCard(message)?.id, 'call_1');
        assert.equal(elicitation({ parts: [toolPart('call_1', 'approval-requested'), { type: 'data-approval', id: 'approval:call_1', data: card }] }), null);
        assert.equal(approvalCard({ parts: [toolPart('call_1', 'approval-requested'), formPart('call_1')] }), null);
    });

    test('is null for a form without its sentence, an object schema of object properties, or all four labels', () => {
        const malformed = [
            null,
            'Fill it in',
            [],
            { ...FORM, action: 7 },
            { ...FORM, params: null },
            { ...FORM, params: { ...PARAMS, message: 42 } },
            { ...FORM, params: { ...PARAMS, requestedSchema: null } },
            { ...FORM, params: { ...PARAMS, requestedSchema: { type: 'array', properties: {} } } },
            { ...FORM, params: { ...PARAMS, requestedSchema: { type: 'object', properties: [] } } },
            { ...FORM, params: { ...PARAMS, requestedSchema: { type: 'object', properties: { title: null } } } },
            { ...FORM, params: { ...PARAMS, requestedSchema: { type: 'object', properties: { title: 'Title' } } } },
            { ...FORM, params: { ...PARAMS, requestedSchema: { ...PARAMS.requestedSchema, required: 'title' } } },
            { ...FORM, labels: null },
            { ...FORM, labels: { ...LABELS, source: undefined } },
            { ...FORM, labels: { ...LABELS, cancel: ['Not now'] } },
        ];

        for (const data of malformed) {
            assert.equal(elicitation({ parts: [toolPart('call_1', 'approval-requested'), formPart('call_1', data)] }), null, JSON.stringify(data));
        }
    });
});

describe('<ElicitationForm>', () => {
    test("renders 7.1's form: the source line, the message, each field labelled and described, and all three buttons", () => {
        assert.equal(
            markup(),
            '<form aria-labelledby="IDm" data-agentic-elicitation="">'
                + '<p data-source=""><bdi>Asked by Laravel</bdi></p>'
                + '<p id="IDm"><bdi>A few details for your post.</bdi></p>'
                + '<div data-field="title"><label for="ID0"><bdi>Title</bdi><span aria-hidden="true">*</span></label>'
                + '<input id="ID0" aria-describedby="ID0d" type="text" required="" minLength="1" maxLength="120" name="title" value="Launch notes"/>'
                + '<div id="ID0d"><div role="alert"></div></div></div>'
                + '<div data-field="body"><label for="ID1"><bdi>Body</bdi><span aria-hidden="true">*</span></label>'
                + '<textarea name="body" id="ID1" aria-describedby="ID1d" required="" maxLength="5000"></textarea>'
                + '<div id="ID1d"><div role="alert"></div></div></div>'
                + '<div data-field="status"><label for="ID2"><bdi>Status</bdi><span aria-hidden="true">*</span></label>'
                + '<select name="status" id="ID2" aria-describedby="ID2d" required=""><option value=""></option><option value="draft">Draft</option><option value="published">Published</option></select>'
                + '<div id="ID2d"><div role="alert"></div></div></div>'
                + '<div><button type="submit">Submit</button><button type="button">Decline</button><button type="button">Not now</button></div>'
                + '</form>',
        );
    });

    test('renders each primitive of the standard with its control, bounds and default', () => {
        const html = markup({ params: EVERY });
        const at = (index) => ({ id: `ID${index}`, 'aria-describedby': `ID${index}d` });

        assert.deepEqual(controls(html, 'name'), [{ tag: 'input', ...at(0), type: 'text', required: '', minLength: '2', maxLength: '40', name: 'name' }]);
        assert.deepEqual(controls(html, 'email'), [{ tag: 'input', ...at(1), type: 'email', name: 'email' }]);
        assert.deepEqual(controls(html, 'site'), [{ tag: 'input', ...at(2), type: 'url', name: 'site' }]);
        assert.deepEqual(controls(html, 'day'), [{ tag: 'input', ...at(3), type: 'date', name: 'day', value: '2026-10-01' }]);
        assert.deepEqual(controls(html, 'notes'), [{ tag: 'textarea', ...at(7), name: 'notes' }]);
        assert.deepEqual(controls(html, 'count'), [{ tag: 'input', ...at(8), type: 'number', required: '', min: '1', max: '10', step: '1', name: 'count', value: '3' }]);
        assert.deepEqual(controls(html, 'ratio'), [{ tag: 'input', ...at(9), type: 'number', min: '0', max: '1', step: 'any', name: 'ratio' }]);
        assert.deepEqual(controls(html, 'share'), [{ tag: 'input', ...at(10), type: 'checkbox', name: 'share', checked: '' }]);
        assert.deepEqual(controls(html, 'notify'), [{ tag: 'input', ...at(11), type: 'checkbox', name: 'notify' }]);
        assert.deepEqual(controls(html, 'size'), [{ tag: 'select', ...at(12), name: 'size', required: '' }]);
        assert.deepEqual(controls(html, 'legacy'), [{ tag: 'select', ...at(14), name: 'legacy' }]);
        assert.deepEqual(controls(html, 'tags'), [
            { tag: 'input', type: 'checkbox', name: 'tags', value: 'news' },
            { tag: 'input', type: 'checkbox', name: 'tags', value: 'blog' },
        ]);
        assert.deepEqual(controls(html, 'owners'), [
            { tag: 'input', type: 'checkbox', name: 'owners', value: '5' },
            { tag: 'input', type: 'checkbox', name: 'owners', checked: '', value: '7' },
        ]);

        assert.match(html, /<div data-field="name"><label for="ID0"><bdi>Name<\/bdi><span aria-hidden="true">\*<\/span><\/label><input [^>]*\/><div id="ID0d"><p>As on your profile\.<\/p><div role="alert"><\/div><\/div><\/div>/);
        assert.match(html, /<div data-field="notes"><label for="ID7"><bdi>notes<\/bdi><\/label><textarea /, 'an untitled field is labelled by its key');
        assert.match(html, /<div data-field="share"><label for="ID10"><bdi>Share<\/bdi><\/label><input /);
        assert.match(html, /<select [^>]*name="size"[^>]*><option value=""><\/option><option value="s">s<\/option><option value="m">m<\/option><option value="l">l<\/option><\/select>/);
        assert.match(html, /<select [^>]*name="status"[^>]*><option value=""><\/option><option value="draft">Draft<\/option><option value="published" selected="">Published<\/option><\/select>/);
        assert.match(html, /<select [^>]*name="legacy"[^>]*><option value=""><\/option><option value="a">Alpha<\/option><option value="b">Beta<\/option><\/select>/);
        assert.match(
            html,
            /<fieldset data-field="tags" aria-describedby="ID15d"><legend><bdi>Tags<\/bdi><span aria-hidden="true">\*<\/span><\/legend><label><input [^>]*\/><bdi>news<\/bdi><\/label><label><input [^>]*\/><bdi>blog<\/bdi><\/label><div id="ID15d"><div role="alert"><\/div><\/div><\/fieldset>/,
        );
        assert.match(html, /<legend><bdi>Owners<\/bdi><\/legend><label><input [^>]*\/><bdi>Ada<\/bdi><\/label><label><input [^>]*\/><bdi>Grace<\/bdi><\/label>/);
    });

    test('a date-time opens in local time, with Z or an offset, and one that does not parse opens empty', () => {
        const html = markup({ params: EVERY });

        assert.deepEqual(controls(html, 'at'), [{ tag: 'input', id: 'ID4', 'aria-describedby': 'ID4d', type: 'datetime-local', name: 'at', value: '2026-09-26T10:30' }]);
        assert.equal(controls(html, 'later')[0].value, '2026-09-26T10:30');
        assert.equal(controls(html, 'never')[0].value, undefined);
    });

    test('required lands on required text, number and select controls, and on no checkbox; the marker is hidden from assistive technology', () => {
        const html = markup({ params: EVERY });
        const all = Object.keys(EVERY.requestedSchema.properties).flatMap((key) => controls(html, key));

        assert.deepEqual(all.filter((control) => 'required' in control).map((control) => control.name), ['name', 'count', 'size', 'status']);
        assert.deepEqual(all.filter((control) => control.type === 'checkbox' && 'required' in control), [], 'no checkbox is required');
        assert.equal((html.match(/<span aria-hidden="true">\*<\/span>/g) ?? []).length, 5, 'name, count, size, status and tags; never the boolean share');
    });

    test('a format or widget the standard does not list renders as plain text', () => {
        const html = markup({
            params: {
                message: 'Odd hints.',
                requestedSchema: {
                    type: 'object',
                    properties: {
                        a: { type: 'string', format: 'hidden' },
                        b: { type: 'string', format: 'constructor' },
                        c: { type: 'string', 'x-agentic-actions': { widget: 'hidden' } },
                    },
                },
            },
        });

        assert.deepEqual(['a', 'b', 'c'].map((key) => controls(html, key)[0].type), ['text', 'text', 'text']);
    });

    test('given errors render under their fields, the control marked invalid and described by them', () => {
        const render = mount({ onAnswer: () => {} });

        render();
        slots[1].value = { body: ['The body field must not be greater than 5000 characters.'], status: ['Pick one.', 'Really.'] };

        const html = renderToStaticMarkup(render()).replaceAll(':r1:', 'ID');

        assert.match(html, /<div data-field="body" data-invalid=""><label for="ID1">.*?<textarea name="body" aria-invalid="true" id="ID1" aria-describedby="ID1d"/);
        assert.match(html, /<div id="ID1d"><div role="alert"><p>The body field must not be greater than 5000 characters\.<\/p><\/div><\/div>/);
        assert.match(html, /<div role="alert"><p>Pick one\.<\/p><p>Really\.<\/p><\/div>/);
        assert.match(html, /<div data-field="title"><label/);
    });

    test('classNames land on their parts', () => {
        const classNames = Object.fromEntries(['form', 'source', 'message', 'field', 'label', 'input', 'description', 'error', 'actions', 'submit', 'decline', 'cancel'].map((part) => [part, `c-${part}`]));
        const render = mount({ params: EVERY, onAnswer: () => {}, classNames });

        render();
        slots[1].value = { name: ['Too short.'] };

        const html = renderToStaticMarkup(render());

        for (const part of Object.keys(classNames)) {
            assert.ok(html.includes(`class="c-${part}"`), part);
        }

        assert.match(html, /<form [^>]*class="c-form">/);
        assert.match(html, /<button type="submit" class="c-submit">Submit<\/button>/);
        assert.deepEqual(controls(html, 'tags').map((control) => control.class), ['c-input', 'c-input']);
    });

    test('Arabic renders as it came, and every string stays text', () => {
        const hostile = '<img src=x onerror=alert(1)>';
        const html = markup({
            params: { message: hostile, requestedSchema: { type: 'object', properties: { [hostile]: { type: 'string', title: hostile, description: hostile, oneOf: [{ const: hostile, title: hostile }] } } } },
            labels: { source: 'طلب من لارافيل', submit: hostile, decline: 'رفض', cancel: 'ليس الآن' },
        });

        assert.ok(!html.includes('<img'));
        assert.match(html, /<bdi>طلب من لارافيل<\/bdi>/);
        assert.match(html, /<button type="button">ليس الآن<\/button>/);
        assert.match(html, /<bdi>&lt;img src=x onerror=alert\(1\)&gt;<\/bdi>/);
    });

    test('a hostile form: every default stays a value and every string text, with no link and no hidden, password or file control', () => {
        const hostile = '<img src=x onerror=alert(1)>';
        const html = markup({
            params: {
                message: 'javascript:alert(1)',
                requestedSchema: {
                    type: 'object',
                    properties: {
                        title: { type: 'string', title: 'Title', description: 'https://example.test', default: hostile },
                        body: { type: 'string', default: `</textarea>${hostile}`, 'x-agentic-actions': { widget: 'textarea' } },
                        site: { type: 'string', format: 'uri', default: 'javascript:alert(1)' },
                        at: { type: 'string', format: 'date-time', default: hostile },
                        status: { type: 'string', oneOf: [{ const: hostile, title: hostile }], default: hostile },
                        tags: { type: 'array', items: { anyOf: [{ const: hostile, title: hostile }] }, default: [hostile] },
                        secret: { type: 'password' },
                        hidden: { type: 'hidden' },
                        upload: { type: 'string', format: 'binary' },
                    },
                },
            },
        });

        assert.ok(!html.includes('<img'));
        assert.ok(!/<a\b|href=|<script|<iframe|type="(password|hidden|file)"/.test(html));
        assert.equal(controls(html, 'title')[0].value, '&lt;img src=x onerror=alert(1)&gt;');
        assert.equal(controls(html, 'site')[0].type, 'url');
        assert.equal(controls(html, 'site')[0].value, 'javascript:alert(1)');
        assert.equal(controls(html, 'at')[0].value, undefined);
        assert.deepEqual(['secret', 'hidden', 'upload'].map((key) => controls(html, key)[0].type), ['number', 'number', 'text']);
        assert.match(html, /<p>javascript:alert\(1\)<\/p>|<bdi>javascript:alert\(1\)<\/bdi>/);
    });
});

describe('<ElicitationForm> answering', () => {
    test('Submit answers accept with the content typed by the schema: numbers, UTC date-times, booleans always, lists as lists', async () => {
        const answers = [];
        const render = mount({ params: EVERY, onAnswer: (result) => answers.push(result) });

        assert.ok(
            submit(render(), {
                name: 'Ada',
                email: '',
                at: '2026-09-26T10:30',
                count: '7',
                ratio: '0.5',
                size: 'm',
                share: false,
                tags: ['news', 'blog'],
            }),
        );

        assert.deepEqual(answers, [
            {
                action: 'accept',
                content: {
                    name: 'Ada',
                    day: '2026-10-01',
                    at: '2026-09-26T07:30:00.000Z',
                    later: '2026-09-26T07:30:00.000Z',
                    count: 7,
                    ratio: 0.5,
                    share: false,
                    notify: false,
                    size: 'm',
                    status: 'published',
                    tags: ['news', 'blog'],
                    owners: ['7'],
                },
            },
        ]);
    });

    test('a multi-select with nothing chosen answers [], and is left out only when optional with minItems above 0', () => {
        const answers = [];
        const lists = {
            message: 'Lists.',
            requestedSchema: {
                type: 'object',
                properties: {
                    required: { type: 'array', items: { enum: ['a'] }, minItems: 1 },
                    optional: { type: 'array', items: { enum: ['a'] } },
                    bounded: { type: 'array', items: { enum: ['a'] }, minItems: 1 },
                    loose: { type: 'array', items: { enum: ['a'] }, minItems: 0 },
                },
                required: ['required'],
            },
        };

        submit(mount({ params: lists, onAnswer: (result) => answers.push(result) })());

        assert.deepEqual(answers[0].content, { required: [], optional: [], loose: [] });
    });

    test('an unchecked required boolean answers false, and empty optional text and number fields are left out', () => {
        const answers = [];
        const params = {
            message: 'Yes or no.',
            requestedSchema: { type: 'object', properties: { agree: { type: 'boolean' }, note: { type: 'string' }, age: { type: 'integer' } }, required: ['agree'] },
        };

        submit(mount({ params, onAnswer: (result) => answers.push(result) })());

        assert.deepEqual(answers, [{ action: 'accept', content: { agree: false } }]);
    });

    test('Decline and Cancel answer their actions without content', async () => {
        const answers = [];

        for (const label of ['Decline', 'Not now']) {
            const render = mount({ onAnswer: (result) => answers.push(result) });

            buttons(render())[label].props.onClick();
            await settle();
        }

        assert.deepEqual(answers, [{ action: 'decline' }, { action: 'cancel' }]);
    });

    test('the buttons wait while an answer is pending; errors it resolves to show, re-enable them, and focus the first invalid control', async () => {
        let resolve;
        const focused = [];
        const render = mount({ onAnswer: () => new Promise((done) => (resolve = done)) });
        const form = find(render(), (element) => element.type === 'form');

        form.props.ref.current = {
            querySelector: (selector) => {
                const control = find(current, (element) => element.props[selector.slice(1, selector.indexOf('='))] === true);

                return control && { focus: () => focused.push(control.props.name) };
            },
        };

        submit(current, { body: 'x'.repeat(5001), status: 'draft' });

        assert.deepEqual(Object.values(buttons(render())).map((button) => button.props.disabled), [true, true, true]);

        resolve({ body: ['The body field must not be greater than 5000 characters.'], status: ['Pick one.'] });
        await settle();

        const tree = render();

        assert.deepEqual(Object.values(buttons(tree)).map((button) => button.props.disabled), [false, false, false]);
        assert.equal(find(tree, (element) => element.props.name === 'body')['props']['aria-invalid'], true);
        assert.equal(text(find(tree, (element) => element.props.id === ':r1:1d')), 'The body field must not be greater than 5000 characters.');
        assert.deepEqual(focused, ['body']);
    });

    test('an answer that resolves to nothing, or to no errors, ends the form: its buttons stay disabled', async () => {
        for (const outcome of [undefined, null, {}]) {
            const render = mount({ onAnswer: async () => outcome });

            submit(render(), { body: 'Fine', status: 'draft' });
            await settle();

            assert.deepEqual(Object.values(buttons(render())).map((button) => button.props.disabled), [true, true, true], String(outcome));
        }
    });

    test('an answer that fails re-enables the buttons, so the person can answer again', async () => {
        const render = mount({ onAnswer: async () => Promise.reject(new Error('offline')) });

        submit(render(), { body: 'Fine', status: 'draft' });
        await settle();

        assert.deepEqual(Object.values(buttons(render())).map((button) => button.props.disabled), [false, false, false]);
    });
});

/** The pause of 7.1: words, one waiting call or two, and their forms. */
const waitingMessage = (ids = ['call_1']) => ({
    id: 'msg-a1',
    role: 'assistant',
    parts: [{ type: 'step-start' }, { type: 'text', text: 'I can draft it once you add a few details.', state: 'done' }, ...ids.map((id) => toolPart(id, 'approval-requested')), ...ids.map((id) => formPart(id))],
});

/** 7.2's answered message, as the server reads it. */
const answeredBody = (elicitationResult, extra = {}) => ({
    ...extra,
    messages: [
        {
            id: 'msg-a1',
            role: 'assistant',
            parts: [
                { type: 'text', text: 'I can draft it once you add a few details.' },
                { type: 'tool-draft-team-post', toolCallId: 'call_1', state: 'approval-responded', approval: { id: 'call_1', approved: elicitationResult.action === 'accept' }, elicitation: elicitationResult },
            ],
        },
    ],
});

let chats = 0;

/** A chat that records its approval responses, and answers them as useChat does, in place. Each has its own id. */
function recordingChat(id = `chat-${++chats}`, messages = [waitingMessage()]) {
    const chat = {
        id,
        messages,
        responses: [],
        addToolApprovalResponse: async ({ id: approval, approved }) => {
            chat.responses.push({ id: approval, approved });
            chat.messages = chat.messages.map((message) => ({
                ...message,
                parts: message.parts.map((part) => (part.approval?.id === approval && part.state === 'approval-requested' ? { ...part, state: 'approval-responded', approval: { ...part.approval, approved } } : part)),
            }));
        },
    };

    return chat;
}

const STREAM_HEADERS = { 'Content-Type': 'text/event-stream; charset=utf-8' };

/** A fetch that answers each request with the next response, and records each request's headers and posted JSON. */
function serving(...answers) {
    const calls = [];

    const fetch = async (url, init) => {
        calls.push({ url, method: init.method, credentials: init.credentials, headers: Object.fromEntries(new Headers(init.headers)), body: JSON.parse(init.body) });

        const answer = answers[calls.length - 1];

        if (answer === undefined) {
            throw new Error(`an unexpected request: ${init.body}`);
        }

        if (answer instanceof Error) {
            throw answer;
        }

        return typeof answer === 'string' ? new Response(answer, { status: 200, headers: STREAM_HEADERS }) : answer;
    };

    return { fetch, calls };
}

const json = (status, body) => new Response(body === undefined ? null : JSON.stringify(body), { status, headers: body === undefined ? {} : { 'Content-Type': 'application/json' } });

/** Send a chat's newest message through a transport, as Chat does. */
const send = (transport, chatId, messages) => transport.sendMessages({ chatId, messages, trigger: 'submit-message', messageId: 'msg-a1', abortSignal: undefined, body: {} });

const ACCEPT = { action: 'accept', content: { title: 'Launch notes', body: 'What shipped this week', status: 'draft' } };

describe('answerElicitation()', () => {
    test("an accept posts one Precognition check with 7.2's body and the transport's headers; a 204 answers the call approved", async () => {
        globalThis.location = { href: 'https://app.test/posts', origin: 'https://app.test' };
        globalThis.document = { cookie: 'XSRF-TOKEN=tok%3D' };

        const { fetch, calls } = serving(json(204));
        const chat = recordingChat();
        const options = { api: '/assistant', fetch, headers: () => ({ 'X-Trace': '1' }), body: () => ({ page: { url: '/teams/acme/posts', component: 'Posts/Index' } }) };

        assert.equal(await answerElicitation(chat, options, 'call_1', ACCEPT), null);

        assert.equal(calls.length, 1);
        assert.deepEqual({ ...calls[0], body: undefined }, {
            url: '/assistant',
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'content-type': 'application/json', accept: 'application/json, text/event-stream', 'x-requested-with': 'XMLHttpRequest', 'x-xsrf-token': 'tok=', 'x-trace': '1', precognition: 'true' },
            body: undefined,
        });
        assert.deepEqual(calls[0].body, answeredBody(ACCEPT, { page: { url: '/teams/acme/posts', component: 'Posts/Index' } }));
        assert.deepEqual(chat.responses, [{ id: 'call_1', approved: true }]);
    });

    test("the check sends the XSRF header to the page's own origin only, and the transport's credentials", async () => {
        globalThis.location = { href: 'https://app.test/posts', origin: 'https://app.test' };
        globalThis.document = { cookie: 'XSRF-TOKEN=tok' };

        const { fetch, calls } = serving(json(204), json(204), json(204));

        await answerElicitation(recordingChat(), { api: 'https://other.test/assistant', fetch }, 'call_1', ACCEPT);
        await answerElicitation(recordingChat(), { api: 'https://other.test/assistant', fetch, credentials: 'include' }, 'call_1', ACCEPT);
        await answerElicitation(recordingChat(), { api: '/assistant', fetch, headers: () => ({ precognition: 'false' }) }, 'call_1', ACCEPT);

        assert.equal(calls[0].headers['x-xsrf-token'], undefined);
        assert.equal(calls[0].credentials, 'same-origin');
        assert.equal(calls[1].headers['x-xsrf-token'], 'tok');
        assert.equal(calls[1].credentials, 'include');
        assert.equal(calls[2].headers.precognition, 'true', 'a host header never turns the check into an answer');
    });

    test('a 422 resolves to the errors by field, answers nothing and stores nothing', async () => {
        const { fetch, calls } = serving(json(422, { message: 'Some answers need another look.', errors: { body: ['Too long.'], status: 'Pick one.' } }), resumeStream());
        const chat = recordingChat('chat-422');

        assert.deepEqual(await answerElicitation(chat, { api: '/assistant', fetch }, 'call_1', ACCEPT), { body: ['Too long.'], status: ['Pick one.'] });
        assert.deepEqual(chat.responses, []);

        chat.messages = [{ ...waitingMessage(), parts: waitingMessage().parts.map((part) => (part.toolCallId ? { ...part, state: 'approval-responded', approval: { id: 'call_1', approved: true } } : part)) }];
        await send(actionsTransport({ api: '/assistant', fetch }), 'chat-422', chat.messages);

        assert.ok(!('elicitation' in calls[1].body.messages[0].parts[1]), 'the refused answer was never stored');
    });

    test('a 422 without errors, a 409, a 419, a 429 or a network error on the check goes on to the real send', async () => {
        for (const answer of [json(422, { message: 'No words.' }), json(409, { message: 'Stale.' }), json(419), json(429), new TypeError('Failed to fetch')]) {
            const { fetch } = serving(answer);
            const chat = recordingChat();

            assert.equal(await answerElicitation(chat, { api: '/assistant', fetch }, 'call_1', ACCEPT), null);
            assert.deepEqual(chat.responses, [{ id: 'call_1', approved: true }]);
        }
    });

    test('Decline and Cancel post nothing first and answer the call not approved, with no content', async () => {
        const { fetch, calls } = serving(resumeStream(), resumeStream());

        for (const action of ['decline', 'cancel']) {
            const chat = recordingChat(`chat-${action}`);

            assert.equal(await answerElicitation(chat, { api: '/assistant', fetch }, 'call_1', { action, content: { body: 'CANARY' } }), null);
            assert.deepEqual(chat.responses, [{ id: 'call_1', approved: false }]);

            await send(actionsTransport({ api: '/assistant', fetch }), chat.id, chat.messages);
        }

        assert.equal(calls.length, 2);
        assert.deepEqual(calls[0].body, answeredBody({ action: 'decline' }));
        assert.deepEqual(calls[1].body, answeredBody({ action: 'cancel' }));
        assert.ok(!JSON.stringify(calls).includes('CANARY'));
    });
});

/** 7.4: the resume turn, continuing the answered message. */
function resumeStream(ids = ['call_1']) {
    return sse([
        { type: 'start', messageId: 'msg-a1' },
        { type: 'start-step' },
        ...ids.flatMap((id) => [
            { type: 'data-action', id: `a:inv-${id}`, data: { action: 'draft-team-post', label: 'Saving…', status: 'running', effect: 'write' } },
            { type: 'data-action', id: `a:inv-${id}`, data: { action: 'draft-team-post', label: 'Saved', status: 'done', effect: 'write', touches: ['posts'] } },
            { type: 'tool-output-available', toolCallId: id, output: null },
        ]),
        { type: 'finish-step' },
        { type: 'start-step' },
        { type: 'text-start', id: 't2' },
        { type: 'text-delta', id: 't2', delta: 'Drafted it.' },
        { type: 'text-end', id: 't2' },
        { type: 'finish-step' },
        { type: 'finish', finishReason: 'stop' },
    ]);
}

/** 7.1: the pause turn. */
function pauseStream(ids = ['call_1']) {
    return sse([
        { type: 'start', messageId: 'msg-a1' },
        { type: 'start-step' },
        { type: 'text-start', id: 't1' },
        { type: 'text-delta', id: 't1', delta: 'I can draft it once you add a few details.' },
        { type: 'text-end', id: 't1' },
        ...ids.flatMap((id) => [
            { type: 'tool-input-available', toolCallId: id, toolName: 'draft-team-post', input: {} },
            { type: 'tool-approval-request', toolCallId: id, approvalId: id },
        ]),
        ...ids.map((id) => formPart(id)),
        { type: 'finish-step' },
        { type: 'finish', finishReason: 'tool-calls' },
    ]);
}

/** A Vercel UI message stream v1 body, one data line per part. */
function sse(parts) {
    return [...parts.map((part) => `data: ${JSON.stringify(part)}\n\n`), 'data: [DONE]\n\n'].join('');
}

describe("the transport's answer body", () => {
    /** The waiting message with the call answered in place, as addToolApprovalResponse() leaves it. */
    const answeredMessage = (approved = true, extra = {}) => ({
        ...waitingMessage(),
        parts: waitingMessage().parts.map((part) => (part.toolCallId ? { ...part, input: { title: 'CANARY-ARG' }, state: 'approval-responded', approval: { id: 'call_1', approved, reason: 'CANARY-REASON' }, ...extra } : part)),
    });

    test('an answered tool part carries elicitation only when an answer is stored for its chat and call, and no input, output or reason beside it', async () => {
        const { fetch, calls } = serving(json(204), resumeStream(), resumeStream());
        const transport = actionsTransport({ api: '/assistant', fetch });

        await answerElicitation(recordingChat('chat-a'), { api: '/assistant', fetch }, 'call_1', ACCEPT);
        await send(transport, 'chat-a', [answeredMessage(true, { output: { secret: 'CANARY-OUT' } })]);
        await send(transport, 'chat-b', [answeredMessage()]);

        assert.deepEqual(calls[1].body.messages[0].parts[1], {
            type: 'tool-draft-team-post',
            toolCallId: 'call_1',
            state: 'approval-responded',
            approval: { id: 'call_1', approved: true },
            elicitation: ACCEPT,
        });
        assert.deepEqual(calls[2].body.messages[0].parts[1].approval, { id: 'call_1', approved: true, reason: 'CANARY-REASON' });
        assert.ok(!('elicitation' in calls[2].body.messages[0].parts[1]), "another chat's call carries none");
        assert.ok(!JSON.stringify(calls[1].body).includes('CANARY'));
    });

    test('an answer is sent once: a later part with the same call id in the same chat carries none', async () => {
        const { fetch, calls } = serving(json(204), resumeStream(), resumeStream());
        const transport = actionsTransport({ api: '/assistant', fetch });

        await answerElicitation(recordingChat('chat-a'), { api: '/assistant', fetch }, 'call_1', ACCEPT);
        await send(transport, 'chat-a', [answeredMessage()]);
        await send(transport, 'chat-a', [answeredMessage()]);

        assert.deepEqual(calls[1].body.messages[0].parts[1].elicitation, ACCEPT);
        assert.ok(!('elicitation' in calls[2].body.messages[0].parts[1]));
    });

    test('two chats with the same call id keep their own answers', async () => {
        const { fetch, calls } = serving(resumeStream(), resumeStream());
        const transport = actionsTransport({ api: '/assistant', fetch });

        await answerElicitation(recordingChat('chat-a'), { api: '/assistant', fetch }, 'call_1', { action: 'decline' });
        await answerElicitation(recordingChat('chat-b'), { api: '/assistant', fetch }, 'call_1', { action: 'cancel' });
        await send(transport, 'chat-b', [answeredMessage(false)]);
        await send(transport, 'chat-a', [answeredMessage(false)]);

        assert.deepEqual(calls.map((call) => call.body.messages[0].parts[1].elicitation), [{ action: 'cancel' }, { action: 'decline' }]);
    });
});

/** A Chat on ai 7's AbstractChat with plain state, as @ai-sdk/react's Chat builds on it. */
class TestChat extends AbstractChat {
    constructor({ messages = [], ...init }) {
        super({
            ...init,
            state: {
                status: 'ready',
                error: undefined,
                messages,
                pushMessage(message) {
                    this.messages = [...this.messages, message];
                },
                popMessage() {
                    this.messages = this.messages.slice(0, -1);
                },
                replaceMessage(index, message) {
                    this.messages = this.messages.map((current, position) => (position === index ? message : current));
                },
                snapshot: (value) => structuredClone(value),
            },
        });
    }
}

/** Wait until the chat has rested for a while: any request it would send by itself has been sent and answered. */
async function idle(chat) {
    for (let tick = 0, quiet = 0; tick < 5000 && quiet < 25; tick++) {
        await new Promise((resolve) => setImmediate(resolve));
        quiet = chat.status === 'ready' || chat.status === 'error' ? quiet + 1 : 0;
    }
}

const toolStates = (chat) => chat.messages.flatMap((message) => message.parts.filter((part) => part.type.startsWith('tool-')).map((part) => [part.toolCallId, part.state]));

describe('the form loop, with a Chat over hand-written streams', () => {
    test('the pause shows the form; answering checks it, then sends one request whose body is the check\'s, and the resume settles it', async () => {
        const { fetch, calls } = serving(pauseStream(), json(204), resumeStream());
        const options = { api: '/assistant', fetch, body: () => ({ page: { url: '/teams/acme/posts', component: 'Posts/Index' } }) };
        const chat = new TestChat(actionsChat(options));

        await chat.sendMessage({ text: 'Draft a team post called Launch notes' });
        await idle(chat);

        const form = elicitation(chat.lastMessage);

        assert.equal(calls.length, 1);
        assert.deepEqual(form, { ...FORM, id: 'call_1' });

        assert.equal(await answerElicitation(chat, options, form.id, ACCEPT), null);
        await idle(chat);

        assert.equal(calls.length, 3);
        assert.equal(calls[1].headers.precognition, 'true');
        assert.equal(calls[2].headers.precognition, undefined);
        assert.deepEqual(calls[2].body, answeredBody(ACCEPT, { page: { url: '/teams/acme/posts', component: 'Posts/Index' } }));
        assert.deepEqual(calls[1].body, calls[2].body, 'one builder for the check and the send');
        assert.deepEqual(toolStates(chat), [['call_1', 'output-available']]);
        assert.equal(elicitation(chat.lastMessage), null);
        assert.deepEqual(actionRows(chat.lastMessage).map((row) => [row.label, row.status]), [['Saved', 'done']]);
        assert.equal(chat.status, 'ready');
    });

    test('a 422 keeps the call waiting and sends nothing more; a corrected answer then goes out', async () => {
        const { fetch, calls } = serving(pauseStream(), json(422, { message: 'Some answers need another look.', errors: { body: ['Too long.'] } }), json(204), resumeStream());
        const options = { api: '/assistant', fetch };
        const chat = new TestChat(actionsChat(options));

        await chat.sendMessage({ text: 'Draft it' });
        await idle(chat);

        assert.deepEqual(await answerElicitation(chat, options, 'call_1', { action: 'accept', content: { title: 'Launch notes', body: 'x'.repeat(5001), status: 'draft' } }), { body: ['Too long.'] });
        await idle(chat);

        assert.equal(calls.length, 2);
        assert.deepEqual(toolStates(chat), [['call_1', 'approval-requested']]);
        assert.equal(elicitation(chat.lastMessage)?.id, 'call_1');

        await answerElicitation(chat, options, 'call_1', ACCEPT);
        await idle(chat);

        assert.equal(calls.length, 4);
        assert.deepEqual(calls[3].body.messages[0].parts[1].elicitation, ACCEPT);
    });

    test('two waiting forms: one at a time, and both answers go out together in one request', async () => {
        const { fetch, calls } = serving(pauseStream(['call_1', 'call_2']), resumeStream(['call_1', 'call_2']));
        const options = { api: '/assistant', fetch };
        const chat = new TestChat(actionsChat(options));

        await chat.sendMessage({ text: 'Draft two' });
        await idle(chat);

        assert.equal(elicitation(chat.lastMessage).id, 'call_1');

        await answerElicitation(chat, options, 'call_1', { action: 'decline' });
        await idle(chat);

        assert.equal(calls.length, 1);
        assert.equal(elicitation(chat.lastMessage).id, 'call_2');

        await answerElicitation(chat, options, 'call_2', { action: 'cancel' });
        await idle(chat);

        assert.equal(calls.length, 2);
        assert.deepEqual(
            calls[1].body.messages[0].parts.filter((part) => part.type !== 'text').map((part) => [part.toolCallId, part.approval.approved, part.elicitation]),
            [
                ['call_1', false, { action: 'decline' }],
                ['call_2', false, { action: 'cancel' }],
            ],
        );
        assert.equal(elicitation(chat.lastMessage), null);
    });
});

/** The workbench's recorded turns (StreamFixturesTest writes them from a real pause and a real resume). */
const recorded = ['elicitation-pause.sse', 'elicitation-resume.sse'].map((name) => new URL(`../fixtures/streams/${name}`, import.meta.url));

describe('the recorded pause and resume', () => {
    /** The parts of a recorded body, as a stream of chunks. */
    const chunks = (file) => {
        const parts = readFileSync(file, 'utf8')
            .split('\n\n')
            .filter((frame) => frame.startsWith('data: ') && frame !== 'data: [DONE]')
            .map((frame) => JSON.parse(frame.slice('data: '.length)));

        return new ReadableStream({
            start(controller) {
                parts.forEach((part) => controller.enqueue(part));
                controller.close();
            },
        });
    };

    /** The last message readUIMessageStream() builds. */
    const read = async (options) => {
        let message;

        for await (const snapshot of readUIMessageStream(options)) {
            message = snapshot;
        }

        return message;
    };

    test('the pause waits on a form the component renders, and the resume settles the same message', async () => {
        const paused = await read({ stream: chunks(recorded[0]) });
        const form = elicitation(paused);

        assert.ok(form !== null, 'a form waits');
        assert.equal(form.params.mode, 'form');
        assert.match(renderToStaticMarkup(React.createElement(ElicitationForm, { params: form.params, labels: form.labels, onAnswer: () => {} })), /^<form aria-labelledby=/);

        const waiting = paused.parts.find((part) => part.type.startsWith('tool-') && part.state === 'approval-requested');

        assert.deepEqual(waiting.input, {});

        const answered = {
            ...paused,
            parts: paused.parts.map((part) => (part === waiting ? { ...part, state: 'approval-responded', approval: { ...part.approval, approved: true } } : part)),
        };
        const resumed = await read({ message: answered, stream: chunks(recorded[1]) });

        assert.equal(resumed.id, paused.id);
        assert.equal(resumed.parts.find((part) => part.toolCallId === waiting.toolCallId).state, 'output-available');
        assert.equal(lastAssistantMessageIsCompleteWithApprovalResponses({ messages: [resumed] }), false);
        assert.equal(elicitation(resumed), null);
    });
});
