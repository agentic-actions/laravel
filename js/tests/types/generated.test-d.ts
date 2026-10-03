// Compiles the actions.ts that EmitterTest writes from the fixture routes against Inertia's own types.
import type { ComponentProps } from 'react';
import type { UrlMethodPair } from '@inertiajs/core';
import { Form, useForm } from '@inertiajs/react';
import type { ActionDefinition } from '@agentic-actions/client';
import * as actions from '../fixtures/actions.js';
import {
    apiPostsArchive,
    archivePost,
    createNote,
    everyShape,
    listNotes,
    teamNote,
    type ApiPostsArchiveParams,
    type ArchivePostInput,
    type ArchivePostOutput,
    type ArchivePostParams,
    type CreateNoteInput,
    type CreateNoteOutput,
    type EveryShapeInput,
    type EveryShapeOutput,
    type ListNotesInput,
    type ListNotesOutput,
    type TeamNoteInput,
    type TeamNoteParams,
} from '../fixtures/actions.js';

type Equal<A, B> = (<T>() => T extends A ? 1 : 2) extends <T>() => T extends B ? 1 : 2 ? true : false;

function expectTrue<T extends true>(): T | void {}

type Json = string | number | boolean | null | Json[] | { [key: string]: Json };

// Every export builds a UrlMethodPair.
type Definitions = { [K in keyof typeof actions]: ReturnType<(typeof actions)[K]> };

expectTrue<Definitions[keyof Definitions] extends UrlMethodPair ? true : false>();

const pairs: UrlMethodPair[] = [
    createNote(),
    listNotes(),
    everyShape(),
    teamNote({ team: 'acme' }),
    archivePost({ post: 1 }),
    apiPostsArchive({ post: '1' }),
    apiPostsArchive({ post: 1, mode: 'soft' }),
];

// The definitions carry their input and output types.
expectTrue<Equal<ReturnType<typeof createNote>, ActionDefinition<CreateNoteInput, CreateNoteOutput>>>();
expectTrue<Equal<CreateNoteInput, { title: string; body: string; excerpt?: string | null }>>();
expectTrue<Equal<CreateNoteOutput, { id: number; title: string }>>();
expectTrue<Equal<ListNotesInput, Record<string, never>>>();
expectTrue<Equal<ListNotesOutput, { posts: { id: number; title: string }[] }>>();
expectTrue<Equal<TeamNoteParams, { team: string | number }>>();
expectTrue<Equal<TeamNoteInput, { title: string }>>();
expectTrue<Equal<ArchivePostParams, { post: string | number }>>();
expectTrue<Equal<ArchivePostInput, { reason?: string }>>();
expectTrue<Equal<ArchivePostOutput, { archived: boolean }>>();
expectTrue<Equal<ApiPostsArchiveParams, { post: string | number; mode?: string | number }>>();
expectTrue<Equal<EveryShapeOutput, Record<string, never>>>();
expectTrue<
    Equal<
        EveryShapeInput,
        {
            status: 'draft' | 'published';
            priority: 1 | 2 | 3;
            ratio?: number;
            flag: boolean;
            tags: string[];
            labels?: ('a' | 'b')[];
            anything?: Json[];
            meta?: Record<string, Json>;
            author: { name: string; email?: string | null };
            lines?: { sku: string; qty?: number }[];
            attachment?: File | Blob;
            'content-type'?: string;
            note: string | null;
        }
    >
>();

// @ts-expect-error a route parameter is required
teamNote();

// @ts-expect-error a parameter value is a string or a number
teamNote({ team: true });

// @ts-expect-error an action without parameters takes none
createNote({ team: 'acme' });

// A definition goes straight to useForm (precognitive) and to <Form action>.
function NewNote(): null {
    const form = useForm(createNote(), { title: '', body: '' });

    form.validate('title');
    expectTrue<Equal<typeof form.data, { title: string; body: string }>>();

    return null;
}

const formProps: ComponentProps<typeof Form> = { action: createNote(), children: null };
const formAction: ComponentProps<typeof Form>['action'] = teamNote({ team: 1 });

export { formAction, formProps, NewNote, pairs };
