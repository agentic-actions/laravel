// Tables: the data-view part's types and helpers in the root, re-exported by /views beside <ActionTable>.
import { formatCell as rootFormatCell, refreshView as rootRefreshView, viewsOf as rootViewsOf, type ActionDataParts } from '@agentic-actions/client';
import type { ActionMessage } from '@agentic-actions/client/ai-sdk';
import { ActionTable, formatCell, refreshView, viewsOf, type ActionTableProps, type TableColumn, type TableData, type ViewData } from '@agentic-actions/client/views';

type Equal<A, B> = (<T>() => T extends A ? 1 : 2) extends <T>() => T extends B ? 1 : 2 ? true : false;

function expectTrue<T extends true>(): T | void {}

// The part the root names is the view the table draws, and either import gives the same helpers.
expectTrue<Equal<ActionDataParts['view'], ViewData>>();
expectTrue<Equal<ViewData['labels'], { refresh: string; truncated?: string }>>();
expectTrue<Equal<TableColumn['type'], 'text' | 'integer' | 'number' | 'money' | 'percent' | 'date' | 'datetime' | 'boolean'>>();
expectTrue<Equal<TableData['rows'][number][string], string | number | boolean | null>>();
expectTrue<Equal<TableData['chart'], { type: 'line' | 'bar' | 'metric' | 'none'; x?: string; y: string[] }>>();
expectTrue<Equal<TableData['caption'], string | null>>();
expectTrue<Equal<typeof viewsOf, typeof rootViewsOf>>();
expectTrue<Equal<typeof formatCell, typeof rootFormatCell>>();
expectTrue<Equal<typeof refreshView, typeof rootRefreshView>>();

// viewsOf() reads a message as useChat holds it, and a data-view part's data is a view.
declare const latest: ActionMessage;
const views: (ViewData & { id: string })[] = viewsOf(latest);

for (const part of latest.parts) {
    if (part.type === 'data-view') {
        expectTrue<Equal<typeof part.data, ViewData>>();
    }
}

const share: string = formatCell(0.25, { key: 'share', label: 'Share', type: 'percent' }, 'ar');
const price: string = formatCell(12, { key: 'price', label: 'Price', type: 'money', currency: 'USD' });

// @ts-expect-error a column's type is one of the eight
formatCell(1, { key: 'words', label: 'Words', type: 'count' });

const fresh: Promise<{ table: TableData; at: string }> = refreshView('/actions/_views', views[0]!.ref!, { signal: new AbortController().signal });

// @ts-expect-error only the signal: the XSRF header goes to the page's own origin, and nowhere else
refreshView('/actions/_views', '01jz3k8m9n0p1q2r3s4t5v6w7x', { credentials: 'include' });

// <ActionTable>'s props, named as /react names each component's.
expectTrue<Equal<ActionTableProps, Parameters<typeof ActionTable>[0]>>();
expectTrue<Equal<ActionTableProps['onRefresh'], ((fresh: { table: TableData; at: string }) => void) | undefined>>();

// @ts-expect-error a class name for a part the table does not have
const badClass: ActionTableProps['classNames'] = { row: 'border-b' };

// @ts-expect-error onRefresh is handed the fresh table and its time
const badRefresh: ActionTableProps['onRefresh'] = (rows: TableData['rows']) => rows;

export function Answer({ message, draw }: { message: ActionMessage; draw: (table: TableData) => void }) {
    return (
        <>
            {viewsOf(message).map((view) => (
                <ActionTable
                    key={view.id}
                    view={view}
                    refreshUrl="/teams/acme/actions/_views"
                    locale="ar"
                    onRefresh={({ table }) => draw(table)}
                    classNames={{ table: 'w-full', number: 'text-end tabular-nums', refresh: 'btn' }}
                />
            ))}
            {/* @ts-expect-error the view is required */}
            <ActionTable refreshUrl="/actions/_views" />
            {share}
            {price}
            {String(fresh)}
            {String(badClass)}
            {String(badRefresh)}
        </>
    );
}
