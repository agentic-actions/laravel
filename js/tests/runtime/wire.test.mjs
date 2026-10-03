import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { basename, join, resolve } from 'node:path';
import { describe, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { readUIMessageStream, uiMessageChunkSchema } from 'ai';
import { actionRows, messageSegments } from '../../dist/index.js';

// The wire, checked against ai 7's own chunk schema: the committed fixture streams, which a workbench test writes from
// real turns, and, when WIRE_STREAMS names a directory, the bodies the stream probe saved through nginx + PHP-FPM.
// Tool parts cross for confirmations and forms only (section 7): a paused call, input-less, and a resumed call, output-less.

const fixtures = fileURLToPath(new URL('../fixtures/streams', import.meta.url));

/** Every .sse file of a directory, sorted. */
const streams = (directory) => readdirSync(directory)
    .filter((name) => name.endsWith('.sse'))
    .sort()
    .map((name) => join(directory, name));

const files = [...streams(fixtures), ...(process.env.WIRE_STREAMS ? streams(resolve(process.env.WIRE_STREAMS)) : [])];

const STATUSES = ['running', 'done', 'refused', 'failed', 'ended', 'declined'];
const EFFECTS = ['read', 'write', 'destructive', 'external'];
const ROW_KEYS = ['action', 'label', 'status', 'effect', 'note', 'touches', 'link'];
const CARD_KEYS = ['action', 'effect', 'label', 'title', 'summary', 'confirm', 'decline'];
const FORM_KEYS = ['action', 'params', 'labels'];
const LABEL_KEYS = ['source', 'submit', 'decline', 'cancel'];
const VIEW_KEYS = ['action', 'table', 'at', 'ref', 'labels'];
const TABLE_KEYS = ['columns', 'rows', 'truncated', 'chart', 'caption'];
const COLUMN_KEYS = ['key', 'label', 'type', 'currency', 'decimals', 'description'];
const COLUMN_TYPES = ['text', 'integer', 'number', 'money', 'percent', 'date', 'datetime', 'boolean'];

/** The keys a requestedSchema property may carry: the standard's, and the one namespaced hint. Never pattern. */
const PROPERTY_KEYS = ['type', 'title', 'description', 'minLength', 'maxLength', 'format', 'minimum', 'maximum', 'enum', 'enumNames', 'oneOf', 'items', 'minItems', 'maxItems', 'default', 'x-agentic-actions'];

/** The tool parts a confirmation sends, each with its keys in order (7.1, 7.3). */
const TOOL_PARTS = {
    'tool-input-available': ['type', 'toolCallId', 'toolName', 'input'],
    'tool-approval-request': ['type', 'toolCallId', 'approvalId'],
    'tool-output-available': ['type', 'toolCallId', 'output'],
    'tool-output-denied': ['type', 'toolCallId'],
    'tool-output-error': ['type', 'toolCallId', 'errorText'],
};

const SETTLING = ['tool-output-available', 'tool-output-denied', 'tool-output-error'];

/** The data lines of a body: each frame is one "data: …" line followed by a blank line. */
function lines(body) {
    const frames = body.split('\n\n');

    assert.equal(frames.pop(), '', 'the body ends with a blank line');

    return frames.map((frame) => {
        assert.match(frame, /^data: [^\n]*$/, `one data line per frame: ${JSON.stringify(frame)}`);

        return frame.slice('data: '.length);
    });
}

/** The keys a part may carry, exactly, in the order the server writes them. */
function allowlistedKeys(part) {
    switch (part.type) {
        case 'start':
            return 'messageId' in part ? ['type', 'messageId'] : ['type'];
        case 'start-step':
        case 'finish-step':
            return ['type'];
        case 'text-start':
        case 'text-end':
            return ['type', 'id'];
        case 'text-delta':
            return ['type', 'id', 'delta'];
        case 'error':
            return ['type', 'errorText'];
        case 'finish':
            return 'finishReason' in part ? ['type', 'finishReason'] : ['type'];
        default:
            if (part.type in TOOL_PARTS) {
                return TOOL_PARTS[part.type];
            }

            if (part.type.startsWith('data-')) {
                return 'id' in part ? ['type', 'id', 'data'] : ['type', 'data'];
            }

            return [];
    }
}

/** A data-action row's data: the known keys only, each present when its rule says so. */
function assertRowData(data) {
    assert.deepEqual(Object.keys(data).filter((key) => !ROW_KEYS.includes(key)), [], 'only the row keys of 6.2');
    assert.equal(typeof data.action, 'string');
    assert.equal(typeof data.label, 'string');
    assert.ok(STATUSES.includes(data.status), `a known status: ${data.status}`);

    if ('effect' in data) {
        assert.ok(EFFECTS.includes(data.effect));
    }

    assert.equal('note' in data, data.status === 'refused' || data.status === 'failed', 'a note exactly on refused and failed rows');

    if (data.status !== 'done') {
        assert.ok(!('touches' in data) && !('link' in data), 'touches and a link only on a done row');
    }

    if ('touches' in data) {
        assert.ok(Array.isArray(data.touches) && data.touches.length > 0 && data.touches.every((key) => typeof key === 'string'));
        assert.notEqual(data.effect, 'read', 'a Read sends no touches');
    }

    if ('link' in data) {
        assert.deepEqual(Object.keys(data.link), ['url', 'follow']);
        assert.equal(typeof data.link.url, 'string');
        assert.equal(typeof data.link.follow, 'boolean');
    }

    for (const value of Object.values(data)) {
        assert.notEqual(value, null, 'empty keys are left out, never null');
    }
}

/** A data-approval card's data: the card's keys only, all text, at most eight rows. */
function assertCardData(data) {
    assert.deepEqual(Object.keys(data), CARD_KEYS);
    assert.ok(['destructive', 'external'].includes(data.effect));

    for (const key of CARD_KEYS.filter((name) => name !== 'summary')) {
        assert.equal(typeof data[key], 'string', `the card's ${key} is text`);
    }

    assert.ok(Array.isArray(data.summary) && data.summary.length <= 8, 'at most eight rows');

    for (const row of data.summary) {
        assert.deepEqual(Object.keys(row), ['label', 'value']);
        assert.ok(typeof row.label === 'string' && typeof row.value === 'string', 'every row is text');
    }
}

/** A data-elicitation form's data: MCP's form params, one hint at most per property, and four labels, all text. */
function assertFormData(data) {
    assert.deepEqual(Object.keys(data), FORM_KEYS);
    assert.equal(typeof data.action, 'string');
    assert.deepEqual(Object.keys(data.params), ['mode', 'message', 'requestedSchema']);
    assert.equal(data.params.mode, 'form');
    assert.equal(typeof data.params.message, 'string');
    assert.deepEqual(Object.keys(data.labels), LABEL_KEYS);
    assert.ok(Object.values(data.labels).every((label) => typeof label === 'string'), 'every label is text');

    const schema = data.params.requestedSchema;

    assert.equal(schema.type, 'object');
    assert.ok(schema.required === undefined || (Array.isArray(schema.required) && schema.required.every((key) => key in schema.properties)));

    for (const [key, property] of Object.entries(schema.properties)) {
        assert.deepEqual(Object.keys(property).filter((name) => !PROPERTY_KEYS.includes(name)), [], `only the standard's keys on ${key}`);
        assert.ok(['string', 'number', 'integer', 'boolean', 'array'].includes(property.type), `a primitive the standard lists: ${key}`);
        assert.ok(property.format === undefined || ['email', 'uri', 'date', 'date-time'].includes(property.format), `a format the standard lists: ${key}`);

        if ('x-agentic-actions' in property) {
            assert.deepEqual(property['x-agentic-actions'], { widget: 'textarea' });
            assert.equal(property.type, 'string');
        }
    }
}

/** A data-view table's data (0.9, 6.3): the part's keys and the table's, known column types, rows of exactly the declared columns holding text, numbers, yes or no, or nothing, and a chart among the columns. */
function assertViewData(data) {
    assert.deepEqual(Object.keys(data), VIEW_KEYS.filter((key) => key in data), 'only the keys of a view, in order');
    assert.ok(typeof data.action === 'string' && typeof data.at === 'string');
    assert.ok(data.ref === undefined || typeof data.ref === 'string');
    assert.deepEqual(Object.keys(data.labels), ['refresh', 'truncated']);
    assert.deepEqual(Object.keys(data.table), TABLE_KEYS);

    const keys = data.table.columns.map((column) => column.key);

    for (const column of data.table.columns) {
        assert.deepEqual(Object.keys(column).filter((key) => !COLUMN_KEYS.includes(key)), [], `only a column's keys on ${column.key}`);
        assert.match(column.key, /^[a-z][a-z0-9_]{0,63}$/);
        assert.ok(COLUMN_TYPES.includes(column.type), `a known type: ${column.type}`);
    }

    for (const row of data.table.rows) {
        assert.deepEqual(Object.keys(row), keys, 'a row holds the declared columns, in order');
        assert.ok(Object.values(row).every((cell) => cell === null || ['string', 'number', 'boolean'].includes(typeof cell)), 'every cell is a scalar or null');
    }

    assert.equal(typeof data.table.truncated, 'boolean');
    assert.ok(data.table.caption === null || typeof data.table.caption === 'string');
    assert.ok([data.table.chart.x, ...data.table.chart.y].every((key) => key === undefined || keys.includes(key)), 'the chart names columns');
}

/**
 * The message a body continues: a resume settles calls that paused in an earlier turn, so the reader needs their
 * answered tool parts first, as useChat holds them. Undefined for a body that starts a message.
 */
function continued(parts) {
    const introduced = new Set(parts.filter((part) => part.type === 'tool-input-available').map((part) => part.toolCallId));
    const resumed = parts.filter((part) => SETTLING.includes(part.type) && !introduced.has(part.toolCallId));

    return resumed.length === 0
        ? undefined
        : {
              id: parts.find((part) => part.type === 'start')?.messageId ?? 'continued',
              role: 'assistant',
              parts: resumed.map((part) => ({
                  type: 'tool-continued',
                  toolCallId: part.toolCallId,
                  state: 'approval-responded',
                  input: {},
                  approval: { id: part.toolCallId, approved: part.type !== 'tool-output-denied' },
              })),
          };
}

/** The parts of a body, and whether it ended with [DONE]. */
function parse(body) {
    const data = lines(body);

    return { parts: data.filter((line) => line !== '[DONE]').map((line) => JSON.parse(line)), data };
}

/** The streams StreamFixturesTest records. Losing one fails here; approval.test and elicitation.test read the pauses and resumes too, and views.test the tables. */
const COMMITTED = ['approval-pause', 'approval-resume', 'elicitation-pause', 'elicitation-resume', 'exception', 'host-parts', 'provider-error', 'refused', 'success', 'views'];

assert.deepEqual(streams(fixtures).map((file) => basename(file, '.sse')), COMMITTED, 'the ten committed fixtures, no fewer and no more');

for (const file of files) {
    const name = file.startsWith(fixtures) ? basename(file) : `probe: ${basename(file)}`;

    describe(name, () => {
        const body = readFileSync(file, 'utf8');

        test('every part validates against ai 7\'s uiMessageChunkSchema', async () => {
            const schema = uiMessageChunkSchema();

            for (const part of parse(body).parts) {
                const result = await schema.validate(part);

                assert.ok(result.success, `${JSON.stringify(part)}: ${result.error?.message}`);
            }
        });

        test('every part carries exactly its allowlisted keys, and no reasoning, source, custom or other tool part exists', () => {
            for (const part of parse(body).parts) {
                assert.equal(typeof part.type, 'string');
                assert.ok(!/^(tool-|reasoning-|source-)/.test(part.type) || part.type in TOOL_PARTS, `an allowlisted type: ${part.type}`);
                assert.ok(part.type !== 'custom' && part.type !== 'file', `an allowlisted type: ${part.type}`);
                assert.deepEqual(Object.keys(part), allowlistedKeys(part), `the keys of ${JSON.stringify(part)}`);

                if (part.type === 'data-action') {
                    assert.match(part.id, /^a:./);
                    assertRowData(part.data);
                }

                if (part.type === 'data-approval') {
                    assert.match(part.id, /^approval:./);
                    assertCardData(part.data);
                }

                if (part.type === 'data-elicitation') {
                    assert.match(part.id, /^elicitation:./);
                    assertFormData(part.data);
                }

                if (part.type === 'data-view') {
                    assert.match(part.id, /^view:./);
                    assertViewData(part.data);
                }

                if (part.type === 'tool-input-available') {
                    assert.deepEqual(part.input, {}, 'a paused call crosses without its input');
                }

                if (part.type === 'tool-output-available') {
                    assert.equal(part.output, null, 'a resumed call crosses without its output');
                }
            }
        });

        test('a tool part crosses only for a paused call, before its request and its card, or to settle a resumed one', () => {
            const { parts } = parse(body);
            const at = (type, id) => parts.findIndex((part) => part.type === type && part.toolCallId === id);

            for (const [index, part] of parts.entries()) {
                if (part.type === 'tool-input-available') {
                    assert.ok(at('tool-approval-request', part.toolCallId) > index, `the call ${part.toolCallId} asks for approval after its tool part`);
                }

                if (part.type === 'tool-approval-request') {
                    assert.equal(part.approvalId, part.toolCallId);
                    assert.ok(at('tool-input-available', part.toolCallId) >= 0 && at('tool-input-available', part.toolCallId) < index, 'its tool part comes first');
                }

                if (part.type === 'data-approval') {
                    assert.ok(at('tool-approval-request', part.id.slice('approval:'.length)) >= 0 && at('tool-approval-request', part.id.slice('approval:'.length)) < index, 'a card follows its request');
                }

                if (part.type === 'data-elicitation') {
                    assert.ok(at('tool-approval-request', part.id.slice('elicitation:'.length)) >= 0 && at('tool-approval-request', part.id.slice('elicitation:'.length)) < index, 'a form follows its request');
                }

                if (SETTLING.includes(part.type)) {
                    assert.equal(at('tool-input-available', part.toolCallId), -1, 'only a call paused in an earlier turn settles');
                }

                if (part.type === 'tool-output-denied') {
                    const row = parts.findIndex((candidate) => candidate.type === 'data-action' && candidate.id === `a:${part.toolCallId}`);

                    assert.ok(row > index && parts[row].data.status === 'declined', 'a declined row follows a denied call');
                }
            }
        });

        test('[DONE] is the last line, and at most one error exists, right before it, with no finish', () => {
            const { parts, data } = parse(body);
            const errors = parts.filter((part) => part.type === 'error');

            assert.equal(data.at(-1), '[DONE]');
            assert.equal(data.filter((line) => line === '[DONE]').length, 1);
            assert.ok(errors.length <= 1, 'one error at most');

            if (errors.length === 1) {
                assert.equal(parts.at(-1).type, 'error');
                assert.ok(!parts.some((part) => part.type === 'finish'), 'a failed turn sends no finish');
            } else {
                assert.equal(parts.at(-1).type, 'finish');
            }
        });

        test('readUIMessageStream() rebuilds the message, and actionRows() keeps one row per id with its last data', async () => {
            const { parts } = parse(body);
            const errors = [];
            const stream = new ReadableStream({
                start(controller) {
                    parts.forEach((part) => controller.enqueue(part));
                    controller.close();
                },
            });

            let message;

            for await (const snapshot of readUIMessageStream({ message: continued(parts), stream, onError: (error) => errors.push(error.message) })) {
                message = snapshot;
            }

            const last = new Map();

            for (const part of parts.filter((candidate) => candidate.type === 'data-action')) {
                last.set(part.id, part.data);
            }

            const rows = actionRows(message);

            assert.equal(message.role, 'assistant');
            assert.deepEqual(rows.map((row) => row.id), [...last.keys()]);
            assert.deepEqual(rows.map((row) => [row.action, row.label, row.status]), [...last.values()].map((data) => [data.action, data.label, data.status]));
            assert.deepEqual(errors, parts.filter((part) => part.type === 'error').map((part) => part.errorText));

            const text = parts.filter((part) => part.type === 'text-delta').map((part) => part.delta).join('');

            assert.equal(message.parts.filter((part) => part.type === 'text').map((part) => part.text).join(''), text);

            // messageSegments() keeps the wire's order: each row where its id first came, each text where it started.
            const words = new Map();

            for (const part of parts) {
                if (part.type === 'text-start' || part.type === 'text-delta') {
                    words.set(part.id, (words.get(part.id) ?? '') + (part.delta ?? ''));
                }
            }

            const wire = [];

            for (const part of parts) {
                if (part.type === 'data-action' && !wire.includes(part.id)) {
                    wire.push(part.id);
                } else if (part.type === 'text-start' && words.get(part.id).trim() !== '') {
                    wire.push(words.get(part.id));
                }
            }

            const segments = messageSegments(message);

            assert.deepEqual(segments.flatMap((segment) => (segment.type === 'text' ? [segment.text] : segment.rows.map((row) => row.id))), wire);
            assert.deepEqual(segments.flatMap((segment) => (segment.type === 'rows' ? segment.rows : [])), rows);
            assert.ok(segments.every((segment, index) => segment.type === 'text' || segments[index - 1]?.type !== 'rows'), 'no two runs of rows touch');
        });
    });
}
