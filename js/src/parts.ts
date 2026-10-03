// Pure helpers for the copilot's data parts: data-action rows, one per tool call, updated in place by id, and the
// data-approval card or data-elicitation form of a call that waits for the person.

export type ActivityStatus = 'running' | 'done' | 'refused' | 'failed' | 'ended' | 'declined';

export type ActionLink = { url: string; follow: boolean };

export type ActionRowData = {
    action: string;
    label: string;
    status: ActivityStatus;
    effect?: 'read' | 'write' | 'destructive' | 'external';
    note?: string;
    /** ['*'] means everything on the page. */
    touches?: string[];
    link?: ActionLink;
};

export type ActionPart = { type: 'data-action'; id: string; data: ActionRowData };

export type ActivityRow = { id: string } & Omit<ActionRowData, 'touches'>;

/** The card a person confirms, as the server built it: the data of a data-approval part. */
export type ApprovalCardData = {
    action: string;
    effect: 'destructive' | 'external';
    /** "Waiting for your confirmation", in the person's language. */
    label: string;
    title: string;
    summary: { label: string; value: string }[];
    confirm: string;
    decline: string;
};

/** A card the person has not answered yet; id is what useChat's addToolApprovalResponse() takes. */
export type WaitingApproval = ApprovalCardData & { id: string };

/** One property of MCP's form-mode requestedSchema: text, number, yes or no, one choice or several. */
export type ElicitationField = {
    type: 'string' | 'number' | 'integer' | 'boolean' | 'array';
    title?: string;
    description?: string;
    minLength?: number;
    maxLength?: number;
    format?: 'email' | 'uri' | 'date' | 'date-time';
    minimum?: number;
    maximum?: number;
    enum?: string[];
    /** The legacy titled enum, still in the standard. */
    enumNames?: string[];
    oneOf?: { const: string; title: string }[];
    items?: { type?: 'string'; enum?: string[]; anyOf?: { const: string; title: string }[] };
    minItems?: number;
    maxItems?: number;
    default?: string | number | boolean | string[];
    /** Hints a standard client ignores. */
    'x-agentic-actions'?: { widget?: 'textarea' };
};

/** MCP's form-mode elicitation params: what the server asks the person. */
export type ElicitationParams = {
    mode?: 'form';
    message: string;
    requestedSchema: { $schema?: string; type: 'object'; properties: Record<string, ElicitationField>; required?: string[] };
};

/** MCP's ElicitResult: the person's answer. */
export type ElicitResult = {
    action: 'accept' | 'decline' | 'cancel';
    content?: Record<string, string | number | boolean | string[]>;
};

/** The data of a data-elicitation part: the action, the standard params, the line naming who asks, and the buttons' labels. */
export type ElicitationData = { action: string; params: ElicitationParams; labels: { source: string; submit: string; decline: string; cancel: string } };

/** A form the person has not answered yet; id is what answerElicitation() takes, the tool-call id the server asks under. */
export type WaitingElicitation = ElicitationData & { id: string };

/** One declared column of a table: currency is money's, decimals is number's, description what it holds, if declared. */
export type TableColumn = {
    key: string;
    label: string;
    type: 'text' | 'integer' | 'number' | 'money' | 'percent' | 'date' | 'datetime' | 'boolean';
    currency?: string;
    decimals?: number;
    description?: string;
};

/** A table action's output, the same on every surface: only the declared columns, and the chart the rows' shape gives. */
export type TableData = {
    columns: TableColumn[];
    rows: Record<string, string | number | boolean | null>[];
    truncated: boolean;
    chart: { type: 'line' | 'bar' | 'metric' | 'none'; x?: string; y: string[] };
    caption: string | null;
};

/** The data of a data-view part: the table the person sees, when it was read, and ref, which refreshes it, when set. */
export type ViewData = { action: string; table: TableData; at: string; ref?: string; labels: { refresh: string; truncated?: string } };

/** The data parts the package sends, for UIMessage's second type parameter. */
export type ActionDataParts = { action: ActionRowData; approval: ApprovalCardData; elicitation: ElicitationData; view: ViewData };

export type ActionRowsOptions = {
    /**
     * The message's turn is over, so a row still running gets no later part: it shows as ended, which claims nothing
     * either way, as a row the server closes does. Pass it for every message but the one the chat is streaming.
     */
    settled?: boolean;
};

/** A message's content in stream order: a text part, or the rows of the tool calls between two of them. */
export type MessageSegment = { type: 'text'; text: string } | { type: 'rows'; rows: ActivityRow[] };

type Message = { parts: ReadonlyArray<{ type: string }> } | undefined;

/** A data-action part with a string id and an object for its data. */
export function isActionPart(part: { type: string }): part is ActionPart {
    const { id, data } = part as { id?: unknown; data?: unknown };

    return part.type === 'data-action' && typeof id === 'string' && typeof data === 'object' && data !== null;
}

/**
 * Same origin as the page, over http or https, or null. The value may come off the wire, so anything but a string is
 * null, and so is a blob: URL or, on a page whose own origin is opaque, a javascript: or data: one.
 */
export function sameOriginUrl(url: unknown): URL | null {
    if (typeof url !== 'string' || typeof location === 'undefined') {
        return null;
    }

    try {
        const parsed = new URL(url, location.href);

        return parsed.origin === location.origin && (parsed.protocol === 'http:' || parsed.protocol === 'https:') ? parsed : null;
    } catch {
        return null;
    }
}

/**
 * The first confirmation a message still waits on, or null: a data-approval card whose tool part still asks for
 * approval. Once the person answers, the tool part moves on and the card is gone; the next waiting one, if any, shows.
 * A card whose text is not all strings is never shown, so a person never confirms half a card.
 */
export function approvalCard(message: Message): WaitingApproval | null {
    return waiting<ApprovalCardData>(
        message,
        'approval',
        (data) =>
            [data?.action, data?.label, data?.title, data?.confirm, data?.decline].every(isText) &&
            Array.isArray(data?.summary) &&
            data.summary.every((row: { label?: unknown; value?: unknown } | null) => isText(row?.label) && isText(row?.value)),
    );
}

/**
 * The first form a message still waits on, or null: a data-elicitation part whose tool part still asks for approval.
 * Once the person answers, the tool part moves on and the form is gone. A form without its sentence, an object schema
 * of object properties, or all four labels is never shown.
 */
export function elicitation(message: Message): WaitingElicitation | null {
    return waiting<ElicitationData>(message, 'elicitation', (data) => {
        const schema = data?.params?.requestedSchema as Partial<ElicitationParams['requestedSchema']> | undefined;

        return (
            [data?.action, data?.params?.message, data?.labels?.source, data?.labels?.submit, data?.labels?.decline, data?.labels?.cancel].every(isText) &&
            schema?.type === 'object' &&
            isObject(schema.properties) &&
            Object.values(schema.properties).every(isObject) &&
            (schema.required === undefined || Array.isArray(schema.required))
        );
    });
}

/**
 * The data of the first data-{kind} part whose tool part still asks for approval, with the approval id, or null. A
 * waiting tool part whose data is missing or fails the check, such as a host tool's, is skipped for the next.
 */
function waiting<T>(message: Message, kind: 'approval' | 'elicitation', valid: (data: Partial<T> | null | undefined) => boolean): (T & { id: string }) | null {
    const parts = (message?.parts ?? []) as ReadonlyArray<{
        type: string;
        id?: unknown;
        data?: unknown;
        toolCallId?: unknown;
        state?: unknown;
        approval?: { id?: unknown } | null;
    }>;

    for (const part of parts) {
        const id = part.approval?.id;

        if (!(part.type.startsWith('tool-') || part.type === 'dynamic-tool') || part.state !== 'approval-requested' || !isText(id) || !isText(part.toolCallId)) {
            continue;
        }

        const data = parts.find((candidate) => candidate.type === `data-${kind}` && candidate.id === `${kind}:${String(part.toolCallId)}`)?.data as Partial<T> | null | undefined;

        if (valid(data)) {
            return { ...(data as T), id };
        }
    }

    return null;
}

/** A plain object, not null and not a list. */
function isObject(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** A string, and nothing else. */
function isText(value: unknown): value is string {
    return typeof value === 'string';
}

/** A pure reducer: a repeated id replaces the row; a cross-origin link is dropped. */
export function applyActionPart(rows: ActivityRow[], part: ActionPart): ActivityRow[] {
    const { touches: _touches, link, ...data } = part.data;
    const row: ActivityRow = { id: part.id, ...data };

    if (sameOriginUrl(link?.url) !== null) {
        row.link = link;
    }

    return rows.some((current) => current.id === part.id)
        ? rows.map((current) => (current.id === part.id ? row : current))
        : [...rows, row];
}

/** A message's rows, one per id, in order: the part reducer over the message's data-action parts. */
export function actionRows(message: Message, options: ActionRowsOptions = {}): ActivityRow[] {
    let rows: ActivityRow[] = [];

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
export function messageSegments(message: Message, options: ActionRowsOptions = {}): MessageSegment[] {
    const segments: MessageSegment[] = [];
    const runs = new Map<string, { type: 'rows'; rows: ActivityRow[] }>();
    let open: { type: 'rows'; rows: ActivityRow[] } | null = null;

    for (const part of message?.parts ?? []) {
        const { text } = part as { text?: unknown };

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
        } else if (part.type === 'text' && typeof text === 'string' && text.trim() !== '') {
            segments.push({ type: 'text', text });
            open = null;
        }
    }

    return segments.map((segment) => (segment.type === 'rows' ? { type: 'rows', rows: settle(segment.rows, options) } : segment));
}

/** Rows of a settled turn: one still running shows as ended. */
function settle(rows: ActivityRow[], options: ActionRowsOptions): ActivityRow[] {
    return options.settled === true ? rows.map((row) => (row.status === 'running' ? { ...row, status: 'ended' } : row)) : rows;
}

type PartListener = { part?: (part: ActionPart) => void; end?: () => void };

const listeners = new Set<PartListener>();

/** A tiny bus, so a chat reader and a sync hook can meet without sharing a component. */
export const actionParts: {
    emit: (part: ActionPart) => void;
    end: () => void;
    subscribe: (listener: { part?: (part: ActionPart) => void; end?: () => void }) => () => void;
} = {
    emit: (part: ActionPart): void => {
        for (const listener of [...listeners]) {
            listener.part?.(part);
        }
    },
    end: (): void => {
        for (const listener of [...listeners]) {
            listener.end?.();
        }
    },
    subscribe: (listener: PartListener): (() => void) => {
        const registration: PartListener = { ...listener };

        listeners.add(registration);

        return () => {
            listeners.delete(registration);
        };
    },
};
