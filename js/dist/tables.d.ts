import type { TableColumn, TableData, ViewData } from './parts.js';
/**
 * The tables a message shows: its data-view parts, in order, each as its data and id. A part without a string id, or
 * whose table has no list of columns or of rows, is left out.
 */
export declare function viewsOf(message: {
    parts: readonly {
        type: string;
    }[];
}): (ViewData & {
    id: string;
})[];
/**
 * A cell as text, by Intl in the locale, else the page's lang, else 'en', which a malformed locale such as en_US also
 * gets. A number shows at most its column's decimals (2, or 0 for an integer), money its currency's own digits, and a
 * percent is a fraction (0.25 is 25%). A date is the day it names in any time zone, a datetime the person's own time,
 * true is ✓, and false, null and undefined are empty. A value Intl refuses reads as itself, so a cell never throws.
 */
export declare function formatCell(value: unknown, column: TableColumn, locale?: string): string;
/**
 * A view's fresh table and its time: callAction() on `${url}/${ref}`, where url is the group's _views route, so the
 * XSRF header goes to the page's own origin only and an error answer rejects with the package's error classes. A ref
 * that is not a ULID rejects before any request, and a success that holds no table rejects with ActionFailedError.
 */
export declare function refreshView(url: string, ref: string, options?: {
    signal?: AbortSignal;
}): Promise<{
    table: TableData;
    at: string;
}>;
