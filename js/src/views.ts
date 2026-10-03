import { createElement, useState, type ReactElement } from 'react';
import { formatCell, refreshView, viewsOf, type TableColumn, type TableData, type ViewData } from '@agentic-actions/client';

export { formatCell, refreshView, viewsOf, type TableColumn, type TableData, type ViewData };

/** The number types, whose cells sit at the end. */
const NUMBER = /^(integer|number|money|percent)$/;

export type ActionTableProps = {
    /** A view from viewsOf(). */
    view: ViewData;
    /** The group's _views route without the ref, {group prefix}/actions/_views. Refresh shows only with it and view.ref. */
    refreshUrl?: string;
    /** The cells' locale; without it, the page's lang. Pass it on a server-rendered page. */
    locale?: string;
    /** Called with the fresh table and its time after a successful refresh, so a chart beside the table can follow. */
    onRefresh?: (fresh: { table: TableData; at: string }) => void;
    /** Class names for the table's parts, for Tailwind or shadcn/ui classes. number is a number's cell, in place of cell. */
    classNames?: Partial<Record<'table' | 'caption' | 'head' | 'cell' | 'number' | 'note' | 'refresh', string>>;
};

/** A view as an unstyled table, with its time and Refresh, disabled while it runs. Key it by view.id. */
export function ActionTable({ view, refreshUrl, locale, onRefresh, classNames = {} }: ActionTableProps): ReactElement {
    const [fresh, setFresh] = useState<{ table: TableData; at: string }>();
    const [busy, setBusy] = useState(false);
    const { table, at } = fresh ?? view;
    const { ref, labels } = view;
    const text = (value: unknown, type: string) => formatCell(value, { type } as TableColumn, locale);
    const end = (column: TableColumn) => (NUMBER.test(column.type) ? { textAlign: 'end' as const } : undefined);

    return createElement(
        'div',
        { 'data-agentic-view': '' },
        createElement(
            'table',
            { className: classNames.table },
            table.caption ? createElement('caption', { className: classNames.caption }, table.caption) : null,
            createElement('thead', null, createElement('tr', null, table.columns.map((column) => createElement('th', { key: column.key, scope: 'col', title: column.description, className: classNames.head, style: end(column) }, column.label)))),
            createElement(
                'tbody',
                null,
                table.rows.map((row, index) =>
                    createElement(
                        'tr',
                        { key: index },
                        table.columns.map((column) =>
                            createElement('td', { key: column.key, className: end(column) ? classNames.number : classNames.cell, style: end(column) }, formatCell(row[column.key], column, locale)),
                        ),
                    ),
                ),
            ),
        ),
        table.truncated && labels.truncated && createElement('p', { className: classNames.note }, labels.truncated.replaceAll(':count', text(table.rows.length, 'integer'))),
        createElement('time', { dateTime: at }, text(at, 'datetime')),
        ref &&
            refreshUrl &&
            createElement(
                'button',
                {
                    type: 'button',
                    disabled: busy,
                    className: classNames.refresh,
                    onClick: () => {
                        setBusy(true);
                        void refreshView(refreshUrl, ref).then(
                            (next) => {
                                setBusy(false);
                                setFresh(next);
                                onRefresh?.(next);
                            },
                            () => setBusy(false),
                        );
                    },
                },
                labels.refresh,
            ),
    );
}
