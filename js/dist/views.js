import { createElement, useState } from 'react';
import { formatCell, refreshView, viewsOf } from '@agentic-actions/client';
export { formatCell, refreshView, viewsOf };
/** The number types, whose cells sit at the end. */
const NUMBER = /^(integer|number|money|percent)$/;
/** A view as an unstyled table, with its time and Refresh, disabled while it runs. Key it by view.id. */
export function ActionTable({ view, refreshUrl, locale, onRefresh, classNames = {} }) {
    const [fresh, setFresh] = useState();
    const [busy, setBusy] = useState(false);
    const { table, at } = fresh ?? view;
    const { ref, labels } = view;
    const text = (value, type) => formatCell(value, { type }, locale);
    const end = (column) => (NUMBER.test(column.type) ? { textAlign: 'end' } : undefined);
    return createElement('div', { 'data-agentic-view': '' }, createElement('table', { className: classNames.table }, table.caption ? createElement('caption', { className: classNames.caption }, table.caption) : null, createElement('thead', null, createElement('tr', null, table.columns.map((column) => createElement('th', { key: column.key, scope: 'col', title: column.description, className: classNames.head, style: end(column) }, column.label)))), createElement('tbody', null, table.rows.map((row, index) => createElement('tr', { key: index }, table.columns.map((column) => createElement('td', { key: column.key, className: end(column) ? classNames.number : classNames.cell, style: end(column) }, formatCell(row[column.key], column, locale))))))), table.truncated && labels.truncated && createElement('p', { className: classNames.note }, labels.truncated.replaceAll(':count', text(table.rows.length, 'integer'))), createElement('time', { dateTime: at }, text(at, 'datetime')), ref &&
        refreshUrl &&
        createElement('button', {
            type: 'button',
            disabled: busy,
            className: classNames.refresh,
            onClick: () => {
                setBusy(true);
                void refreshView(refreshUrl, ref).then((next) => {
                    setBusy(false);
                    setFresh(next);
                    onRefresh?.(next);
                }, () => setBusy(false));
            },
        }, labels.refresh));
}
