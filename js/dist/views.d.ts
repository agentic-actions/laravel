import { type ReactElement } from 'react';
import { formatCell, refreshView, viewsOf, type TableColumn, type TableData, type ViewData } from '@agentic-actions/client';
export { formatCell, refreshView, viewsOf, type TableColumn, type TableData, type ViewData };
export type ActionTableProps = {
    /** A view from viewsOf(). */
    view: ViewData;
    /** The group's _views route without the ref, {group prefix}/actions/_views. Refresh shows only with it and view.ref. */
    refreshUrl?: string;
    /** The cells' locale; without it, the page's lang. Pass it on a server-rendered page. */
    locale?: string;
    /** Called with the fresh table and its time after a successful refresh, so a chart beside the table can follow. */
    onRefresh?: (fresh: {
        table: TableData;
        at: string;
    }) => void;
    /** Class names for the table's parts, for Tailwind or shadcn/ui classes. number is a number's cell, in place of cell. */
    classNames?: Partial<Record<'table' | 'caption' | 'head' | 'cell' | 'number' | 'note' | 'refresh', string>>;
};
/** A view as an unstyled table, with its time and Refresh, disabled while it runs. Key it by view.id. */
export declare function ActionTable({ view, refreshUrl, locale, onRefresh, classNames }: ActionTableProps): ReactElement;
