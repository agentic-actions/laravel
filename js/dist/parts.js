// Pure helpers for the copilot's data parts: data-action rows, one per tool call, updated in place by id, and the
// data-approval card or data-elicitation form of a call that waits for the person.
/** A data-action part with a string id and an object for its data. */
export function isActionPart(part) {
    const { id, data } = part;
    return part.type === 'data-action' && typeof id === 'string' && typeof data === 'object' && data !== null;
}
/**
 * Same origin as the page, over http or https, or null. The value may come off the wire, so anything but a string is
 * null, and so is a blob: URL or, on a page whose own origin is opaque, a javascript: or data: one.
 */
export function sameOriginUrl(url) {
    if (typeof url !== 'string' || typeof location === 'undefined') {
        return null;
    }
    try {
        const parsed = new URL(url, location.href);
        return parsed.origin === location.origin && (parsed.protocol === 'http:' || parsed.protocol === 'https:') ? parsed : null;
    }
    catch {
        return null;
    }
}
/**
 * The first confirmation a message still waits on, or null: a data-approval card whose tool part still asks for
 * approval. Once the person answers, the tool part moves on and the card is gone; the next waiting one, if any, shows.
 * A card whose text is not all strings is never shown, so a person never confirms half a card.
 */
export function approvalCard(message) {
    return waiting(message, 'approval', (data) => [data?.action, data?.label, data?.title, data?.confirm, data?.decline].every(isText) &&
        Array.isArray(data?.summary) &&
        data.summary.every((row) => isText(row?.label) && isText(row?.value)));
}
/**
 * The first form a message still waits on, or null: a data-elicitation part whose tool part still asks for approval.
 * Once the person answers, the tool part moves on and the form is gone. A form without its sentence, an object schema
 * of object properties, or all four labels is never shown.
 */
export function elicitation(message) {
    return waiting(message, 'elicitation', (data) => {
        const schema = data?.params?.requestedSchema;
        return ([data?.action, data?.params?.message, data?.labels?.source, data?.labels?.submit, data?.labels?.decline, data?.labels?.cancel].every(isText) &&
            schema?.type === 'object' &&
            isObject(schema.properties) &&
            Object.values(schema.properties).every(isObject) &&
            (schema.required === undefined || Array.isArray(schema.required)));
    });
}
/**
 * The data of the first data-{kind} part whose tool part still asks for approval, with the approval id, or null. A
 * waiting tool part whose data is missing or fails the check, such as a host tool's, is skipped for the next.
 */
function waiting(message, kind, valid) {
    const parts = (message?.parts ?? []);
    for (const part of parts) {
        const id = part.approval?.id;
        if (!(part.type.startsWith('tool-') || part.type === 'dynamic-tool') || part.state !== 'approval-requested' || !isText(id) || !isText(part.toolCallId)) {
            continue;
        }
        const data = parts.find((candidate) => candidate.type === `data-${kind}` && candidate.id === `${kind}:${String(part.toolCallId)}`)?.data;
        if (valid(data)) {
            return { ...data, id };
        }
    }
    return null;
}
/** A plain object, not null and not a list. */
function isObject(value) {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}
/** A string, and nothing else. */
function isText(value) {
    return typeof value === 'string';
}
/** A pure reducer: a repeated id replaces the row; a cross-origin link is dropped. */
export function applyActionPart(rows, part) {
    const { touches: _touches, link, ...data } = part.data;
    const row = { id: part.id, ...data };
    if (sameOriginUrl(link?.url) !== null) {
        row.link = link;
    }
    return rows.some((current) => current.id === part.id)
        ? rows.map((current) => (current.id === part.id ? row : current))
        : [...rows, row];
}
/** A message's rows, one per id, in order: the part reducer over the message's data-action parts. */
export function actionRows(message, options = {}) {
    let rows = [];
    for (const part of message?.parts ?? []) {
        if (isActionPart(part)) {
            rows = applyActionPart(rows, part);
        }
    }
    return settle(rows, options);
}
/**
 * A message's text parts and the runs of rows between them, in stream order. Consecutive data-action parts make one
 * run, and a later part for a row updates it in the run where it first appeared. Other parts, and text that is only
 * white space, are left out, so they never split a run.
 */
export function messageSegments(message, options = {}) {
    const segments = [];
    const runs = new Map();
    let open = null;
    for (const part of message?.parts ?? []) {
        const { text } = part;
        if (isActionPart(part)) {
            let run = runs.get(part.id);
            if (run === undefined) {
                if (open === null) {
                    open = { type: 'rows', rows: [] };
                    segments.push(open);
                }
                run = open;
                runs.set(part.id, run);
            }
            run.rows = applyActionPart(run.rows, part);
        }
        else if (part.type === 'text' && typeof text === 'string' && text.trim() !== '') {
            segments.push({ type: 'text', text });
            open = null;
        }
    }
    return segments.map((segment) => (segment.type === 'rows' ? { type: 'rows', rows: settle(segment.rows, options) } : segment));
}
/** Rows of a settled turn: one still running shows as ended. */
function settle(rows, options) {
    return options.settled === true ? rows.map((row) => (row.status === 'running' ? { ...row, status: 'ended' } : row)) : rows;
}
const listeners = new Set();
/** A tiny bus, so a chat reader and a sync hook can meet without sharing a component. */
export const actionParts = {
    emit: (part) => {
        for (const listener of [...listeners]) {
            listener.part?.(part);
        }
    },
    end: () => {
        for (const listener of [...listeners]) {
            listener.end?.();
        }
    },
    subscribe: (listener) => {
        const registration = { ...listener };
        listeners.add(registration);
        return () => {
            listeners.delete(registration);
        };
    },
};
