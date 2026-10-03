import { callAction } from './call.js';
import { ActionFailedError } from './errors.js';
// The copilot's tables without React: a message's data-view parts, a cell's text, and a table's fresh rows.
/** A view's ref: a ULID, 26 characters of Crockford's base32, in either case (Laravel writes them in lower case). */
const ULID = /^[0-9a-hjkmnp-tv-z]{26}$/i;
/** Each number type's Intl style and most fraction digits; money keeps its currency's own. */
const NUMBERS = {
    integer: ['decimal', 0],
    number: ['decimal', 2],
    money: ['currency'],
    percent: ['percent', 2],
};
/** Formatters by locale and column: building one costs far more than using it. */
const formatters = new Map();
/**
 * The tables a message shows: its data-view parts, in order, each as its data and id. A part without a string id, or
 * whose table has no list of columns or of rows, is left out.
 */
export function viewsOf(message) {
    return message.parts.flatMap((part) => {
        const { id, data } = part;
        return part.type === 'data-view' && typeof id === 'string' && data && isTable(data.table) ? [{ ...data, id }] : [];
    });
}
/**
 * A cell as text, by Intl in the locale, else the page's lang, else 'en', which a malformed locale such as en_US also
 * gets. A number shows at most its column's decimals (2, or 0 for an integer), money its currency's own digits, and a
 * percent is a fraction (0.25 is 25%). A date is the day it names in any time zone, a datetime the person's own time,
 * true is ✓, and false, null and undefined are empty. A value Intl refuses reads as itself, so a cell never throws.
 */
export function formatCell(value, column, locale) {
    const { type, currency, decimals } = column;
    const lang = locale || globalThis.document?.documentElement?.lang || 'en';
    if (value === null || value === undefined) {
        return '';
    }
    if (type === 'boolean') {
        return value === true ? '✓' : '';
    }
    if (NUMBERS[type] === undefined && type !== 'date' && type !== 'datetime') {
        return String(value);
    }
    try {
        const key = `${lang} ${type} ${currency} ${decimals}`;
        const format = formatters.get(key) ?? formatter(lang, column);
        formatters.set(key, format);
        return format(value);
    }
    catch {
        return lang === 'en' ? String(value) : formatCell(value, column, 'en');
    }
}
/**
 * A view's fresh table and its time: callAction() on `${url}/${ref}`, where url is the group's _views route, so the
 * XSRF header goes to the page's own origin only and an error answer rejects with the package's error classes. A ref
 * that is not a ULID rejects before any request, and a success that holds no table rejects with ActionFailedError.
 */
export async function refreshView(url, ref, options = {}) {
    if (!ULID.test(ref)) {
        throw new Error('The view ref must be a ULID.');
    }
    // Only the signal is passed on, so no option sends the XSRF header anywhere else.
    const fresh = await callAction({ name: '_views', method: 'post', url: `${url}/${encodeURIComponent(ref)}`, touches: [] }, {}, { signal: options.signal });
    if (!isTable(fresh?.table) || typeof fresh?.at !== 'string') {
        throw new ActionFailedError('', 200);
    }
    return { table: fresh.table, at: fresh.at };
}
/**
 * The function that writes a number or a date column's values in the locale. Throws on a malformed locale or currency,
 * and its result throws on a date that names no day.
 */
function formatter(lang, { type, currency, decimals }) {
    const number = NUMBERS[type];
    if (number) {
        const numbers = new Intl.NumberFormat(lang, { style: number[0], currency, maximumFractionDigits: decimals ?? number[1] });
        return (value) => numbers.format(value);
    }
    // A date names a day, so it is read and written in UTC; a datetime is a moment, written in the person's zone.
    const dates = new Intl.DateTimeFormat(lang, type === 'date' ? { dateStyle: 'medium', timeZone: 'UTC' } : { dateStyle: 'medium', timeStyle: 'short' });
    return (value) => dates.format(new Date(value));
}
/** Whether a value holds a table's columns and rows as lists. */
function isTable(table) {
    const { columns, rows } = (table ?? {});
    return Array.isArray(columns) && Array.isArray(rows);
}
