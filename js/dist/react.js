import { createElement, useEffect, useId, useRef, useState } from 'react';
import { Link, useHttp } from '@inertiajs/react';
import { HttpResponseError } from '@inertiajs/core';
import { actionParts, createActionSync, editors, newKey, notifyTouched, sameOriginUrl, } from '@agentic-actions/client';
import { inertiaApply, installInertiaReload } from '@agentic-actions/client/inertia';
// A React Inertia app changes nothing: touches reload the page as before.
installInertiaReload();
/** Precognition's handlers for the statuses below 500 it would otherwise reject: all but 422's field errors. */
const REFUSING = ['onUnauthorized', 'onForbidden', 'onNotFound', 'onConflict', 'onLocked'];
/**
 * Inertia's useHttp for an action: the same errors, processing and precognitive validate(), plus run() and refusal.
 * run() resolves the output, or undefined when refused: field errors land on errors, anything else on refusal.
 * validate() does the same with a 401, 403, 404, 409 or 423, so every status settles the check.
 */
export function useAction(definition, initial, options = {}) {
    const form = useHttp(definition, initial);
    // Minted on the first run(); a retry reuses it until one succeeds.
    const key = useRef(options.idempotencyKey ?? null);
    const [refused, setRefused] = useState(null);
    const run = async (submit = {}) => {
        setRefused(null);
        key.current ??= newKey();
        try {
            return await form.submit({
                ...submit,
                headers: { ...submit.headers, 'Idempotency-Key': key.current },
                onSuccess: (output, response) => {
                    key.current = null;
                    notifyTouched(definition.touches, definition);
                    submit.onSuccess?.(output, response);
                },
            });
        }
        catch (error) {
            if (error instanceof HttpResponseError && error.response.status < 500) {
                setRefused(refusalMessage(error.response.data) ?? String(error.response.status));
                return undefined;
            }
            throw error;
        }
    };
    // The caller's config, plus a refusal for each status run() would refuse, cleared as each check starts.
    const refusing = (config = {}) => ({
        ...config,
        ...Object.fromEntries(REFUSING.map((name) => [
            name,
            (response, error) => {
                setRefused(refusalMessage(response.data) ?? String(response.status));
                return config[name]?.(response, error);
            },
        ])),
        onStart: () => {
            setRefused(null);
            config.onStart?.();
        },
    });
    const precognitive = form.validate;
    // validate(field?, config?) or validate(config), read as Inertia reads them.
    const validate = ((field, config) => typeof field === 'object' && field !== null && !('target' in field)
        ? precognitive(refusing(field))
        : precognitive(field, refusing(config)));
    // A 422 on a key whose first segment the form does not hold, such as a route parameter, is a refusal too.
    const errors = form.errors;
    const stray = Object.keys(errors).find((field) => !(field.split('.')[0] in form.data));
    const refusal = refused ?? (stray === undefined ? null : (errors[stray] ?? null));
    return Object.assign(form, { run, refusal, validate });
}
/** The body's message, or null without a non-empty string message. A body is JSON text, or already parsed by Precognition. */
function refusalMessage(body) {
    try {
        const parsed = (typeof body === 'string' ? JSON.parse(body) : body);
        return typeof parsed?.message === 'string' && parsed.message !== '' ? parsed.message : null;
    }
    catch {
        return null;
    }
}
/**
 * One coalesced refresh per burst of done rows, held while an editor is dirty, and run by itself once none is, unless
 * resumeWhenClean is false. Default apply: inertiaApply. It reads the data-action parts an actionsChat() Chat emits; a
 * host with its own reader calls onPart() itself. when, how, apply, resumeWhenClean and feed are read at mount: a page
 * whose feed URL changes remounts the component that calls it (key={feedUrl}). Every function it returns can be
 * destructured or passed on unbound.
 */
export function useActionSync(options = {}) {
    const latest = useRef(options);
    latest.current = options;
    const [waiting, setWaiting] = useState(false);
    const [mounted] = useState(options);
    const current = useRef(null);
    const [calls] = useState(() => ({
        apply: () => current.current?.apply(),
        flush: () => current.current?.flush(),
        onPart: (part) => current.current?.onPart(part),
        touch: (keys) => current.current?.touch(keys),
    }));
    const blocked = options.blocked ?? false;
    // The sync lives from mount to unmount, never in render, so its feed poller stops with the component, and a
    // remount (StrictMode's included) starts a fresh one.
    useEffect(() => {
        const sync = createActionSync({
            apply: mounted.apply ?? inertiaApply,
            when: mounted.when,
            how: mounted.how,
            resumeWhenClean: mounted.resumeWhenClean,
            feed: mounted.feed,
            blocked: () => latest.current.blocked ?? false,
            onWaiting: setWaiting,
        });
        const unsubscribe = actionParts.subscribe({ part: sync.onPart, end: sync.flush });
        current.current = sync;
        return () => {
            unsubscribe();
            sync.dispose();
            current.current = null;
        };
    }, [mounted]);
    // The sync cannot see the host's flag turn false, so a held refresh is checked again here; the sync reads every hold.
    useEffect(() => {
        if (mounted.resumeWhenClean !== false && waiting && !blocked) {
            calls.flush();
        }
    }, [calls, mounted, waiting, blocked]);
    return { waiting, ...calls };
}
/** Register an editor: while it is dirty, every copilot refresh and follow waits, and useActionSync's waiting turns true. */
export function useActionEdits(dirty) {
    const [key] = useState(() => Symbol('editor'));
    useEffect(() => {
        editors.set(key, dirty);
        return () => editors.set(key, false);
    }, [key, dirty]);
}
/** Rows, unstyled and accessible. A host shows fewer with rows.slice(-3), and styles through the data-* attributes. */
export function ActionActivity({ rows }) {
    if (rows.length === 0) {
        return null;
    }
    return createElement('ol', { 'aria-live': 'polite', 'data-agentic-activity': '' }, rows.map((row) => {
        // <bdi> keeps a Latin name or a number from reordering an Arabic line.
        const label = createElement('bdi', null, row.label);
        // The checked URL itself is what the link carries.
        const url = sameOriginUrl(row.link?.url);
        return createElement('li', { key: row.id, 'data-status': row.status, 'data-effect': row.effect }, url === null ? label : createElement(Link, { href: url.href }, label), row.note === undefined ? null : createElement('span', { 'data-note': '' }, row.note));
    }));
}
/** One waiting confirmation, unstyled: the server's sentence and rows, and two buttons. onAnswer(true) confirms. */
export function ApprovalCard({ approval, onAnswer }) {
    return createElement('section', { 'aria-label': approval.label, 'data-agentic-approval': '', 'data-effect': approval.effect }, createElement('p', null, createElement('bdi', null, approval.title)), approval.summary.length === 0
        ? null
        : createElement('dl', null, approval.summary.map((row, index) => createElement('div', { key: index }, createElement('dt', null, row.label), createElement('dd', null, createElement('bdi', null, row.value))))), createElement('button', { type: 'button', onClick: () => onAnswer(true) }, approval.confirm), createElement('button', { type: 'button', onClick: () => onAnswer(false) }, approval.decline));
}
/** Any MCP form-mode elicitation as an accessible form. */
export function ElicitationForm({ params, labels, onAnswer, classNames = {} }) {
    const id = useId();
    const form = useRef(null);
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const { properties, required = [] } = params.requestedSchema;
    const fields = Object.entries(properties).map(([key, field]) => [key, field, control(field), choices(field) ?? []]);
    useEffect(() => form.current?.querySelector('[aria-invalid="true"]')?.focus(), [errors]);
    const answer = async (result) => {
        setBusy(true);
        try {
            const returned = await onAnswer(result);
            const invalid = !!returned && Object.keys(returned).length > 0;
            setErrors(invalid ? returned : {});
            setBusy(!invalid);
        }
        catch {
            setBusy(false);
        }
    };
    const submit = (event) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        const content = {};
        for (const [key, field, kind] of fields) {
            const values = data.getAll(key);
            const value = values[0];
            if (kind === 'checkbox') {
                content[key] = value !== undefined;
            }
            else if (kind === 'group') {
                if (value !== undefined || required.includes(key) || !field.minItems) {
                    content[key] = values;
                }
            }
            else if (value) {
                content[key] = kind === 'number' ? Number(value) : kind === 'datetime-local' ? new Date(value).toISOString() : value;
            }
        }
        void answer({ action: 'accept', content });
    };
    return createElement('form', { ref: form, onSubmit: submit, 'aria-labelledby': `${id}m`, 'data-agentic-elicitation': '', className: classNames.form }, createElement('p', { 'data-source': '', className: classNames.source }, createElement('bdi', null, labels.source)), createElement('p', { id: `${id}m`, className: classNames.message }, createElement('bdi', null, params.message)), fields.map(([key, field, kind, options], index) => {
        const at = id + index;
        const group = kind === 'group';
        const tag = kind === 'select' || kind === 'textarea' ? kind : 'input';
        const needed = required.includes(key) && kind !== 'checkbox';
        const initial = kind === 'datetime-local' ? localTime(field.default) : field.default;
        const messages = errors[key] ?? [];
        const invalid = messages.length > 0 || undefined;
        const shared = { name: key, className: classNames.input, 'aria-invalid': invalid };
        return createElement(group ? 'fieldset' : 'div', { key, 'data-field': key, 'data-invalid': invalid && '', 'aria-describedby': group ? `${at}d` : undefined, className: classNames.field }, createElement(group ? 'legend' : 'label', { htmlFor: group ? undefined : at, className: classNames.label }, createElement('bdi', null, field.title ?? key), needed ? createElement('span', { 'aria-hidden': true }, '*') : null), group
            ? options.map((option) => createElement('label', { key: option.const }, createElement('input', { ...shared, type: 'checkbox', value: option.const, defaultChecked: [initial].flat().includes(option.const) }), createElement('bdi', null, option.title)))
            : createElement(tag, {
                ...shared,
                id: at,
                'aria-describedby': `${at}d`,
                type: tag === 'input' ? kind : undefined,
                required: needed,
                minLength: field.minLength,
                maxLength: field.maxLength,
                min: field.minimum,
                max: field.maximum,
                step: kind === 'number' ? (field.type === 'integer' ? 1 : 'any') : undefined,
                [kind === 'checkbox' ? 'defaultChecked' : 'defaultValue']: kind === 'checkbox' ? initial === true : initial,
            }, tag === 'select' ? [createElement('option', { key: '', value: '' }), ...options.map((option) => createElement('option', { key: option.const, value: option.const }, option.title))] : undefined), createElement('div', { id: `${at}d` }, field.description && createElement('p', { className: classNames.description }, field.description), createElement('div', { role: 'alert' }, messages.map((message, position) => createElement('p', { key: position, className: classNames.error }, message)))));
    }), createElement('div', { className: classNames.actions }, createElement('button', { type: 'submit', disabled: busy, className: classNames.submit }, labels.submit), ['decline', 'cancel'].map((action) => createElement('button', { key: action, type: 'button', disabled: busy, className: classNames[action], onClick: () => void answer({ action }) }, labels[action]))));
}
/** How a field renders. */
function control(field) {
    const { type, format } = field;
    return type === 'array' ? 'group' : choices(field) ? 'select' : type === 'boolean' ? 'checkbox' : type !== 'string' ? 'number' : field['x-agentic-actions']?.widget === 'textarea' ? 'textarea' : format === 'uri' ? 'url' : format === 'date-time' ? 'datetime-local' : format === 'email' || format === 'date' ? format : 'text';
}
/** A field's options. */
function choices(field) {
    const list = field.type === 'array' ? (field.items?.anyOf ?? field.items?.enum) : (field.oneOf ?? field.enum);
    return list?.map((option, index) => (typeof option === 'string' ? { const: option, title: field.enumNames?.[index] ?? option } : option));
}
/** A date-time's local time, for a datetime-local input. */
function localTime(value) {
    const date = new Date(String(value));
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return isNaN(+date) ? undefined : date.toISOString().slice(0, 16);
}
