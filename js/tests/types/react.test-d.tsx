// useAction over the generated fixtures: the same surface as Inertia's useHttp, plus run() and refusal.
import { actionRows, approvalCard, elicitation, type ActionPart, type ElicitResult, type WaitingApproval } from '@agentic-actions/client';
import { inertiaApply } from '@agentic-actions/client/inertia';
import {
    ActionActivity,
    ApprovalCard,
    ElicitationForm,
    useAction,
    useActionEdits,
    useActionSync,
    type ActionActivityProps,
    type ApprovalCardProps,
    type ElicitationFormProps,
    type UseActionOptions,
    type UseActionSyncOptions,
} from '@agentic-actions/client/react';
import { createNote, teamNote, type CreateNoteInput, type CreateNoteOutput } from '../fixtures/actions.js';

type Equal<A, B> = (<T>() => T extends A ? 1 : 2) extends <T>() => T extends B ? 1 : 2 ? true : false;

function expectTrue<T extends true>(): T | void {}

export function NewNote() {
    const note = useAction(createNote(), { title: '', body: '' });

    const titleError: string | undefined = note.errors.title;
    const processing: boolean = note.processing;
    const refusal: string | null = note.refusal;
    const result: Promise<CreateNoteOutput | undefined> = note.run();
    const response: CreateNoteOutput | null = note.response;

    expectTrue<Equal<Awaited<ReturnType<typeof note.run>>, CreateNoteOutput | undefined>>();
    expectTrue<Equal<typeof note.data, CreateNoteInput>>();

    // Inertia's precognitive validation comes with it, with Inertia's signature and chaining.
    note.validate('title');
    note.validate({ only: ['title', 'body'] });
    note.validate('title', { onForbidden: (response) => response.status }).touch('body');
    note.setData('title', 'Hello');

    // @ts-expect-error a field the input does not have
    note.validate('nope');

    // run() takes useHttp's submit options, typed by the output.
    note.run({
        headers: { 'X-Trace': '1' },
        onSuccess: (output) => {
            expectTrue<Equal<typeof output, CreateNoteOutput>>();
        },
    });

    // @ts-expect-error a field the input does not have
    note.errors.nope;

    // @ts-expect-error run() does not resolve a string
    const wrong: Promise<string> = note.run();

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                void note.run();
            }}
        >
            <input value={note.data.title} onChange={(event) => note.setData('title', event.target.value)} />
            {titleError && <p>{titleError}</p>}
            {refusal && <p role="alert">{refusal}</p>}
            <button disabled={processing}>Create</button>
            {String(result)}
            {response?.title}
            {String(wrong)}
        </form>
    );
}

export function TeamNote({ team, idempotencyKey }: { team: string; idempotencyKey: string }) {
    const options: UseActionOptions = { idempotencyKey };
    const note = useAction(teamNote({ team }), { title: '' }, options);

    // @ts-expect-error the initial data must match the input
    useAction(createNote(), { title: 1, body: '' });

    // @ts-expect-error the initial data must hold every required key
    useAction(createNote(), { title: '' });

    const initial: CreateNoteInput = { title: '', body: '', excerpt: null };

    useAction(createNote(), initial);

    return <p>{note.refusal}</p>;
}

// 0.2: the copilot's sync, the editor guard and the rows.
export function AssistantPanel({ latest, part }: { latest: { id: string; role: 'assistant'; parts: { type: string }[] }; part: ActionPart }) {
    const { waiting, apply, flush, onPart, touch } = useActionSync();

    onPart(part);
    touch(['posts']);
    flush();

    const turn = useActionSync({ when: 'turn', how: 'remount', blocked: false, resumeWhenClean: false, apply: (request) => inertiaApply(request) });
    const waitingIsBoolean: boolean = turn.waiting;
    const options: UseActionSyncOptions = { blocked: true };
    const polled = useActionSync({ feed: { url: '/teams/acme/actions/_changes', interval: 15_000 } });
    const polledWaiting: boolean = polled.waiting;

    // @ts-expect-error the feed needs its url
    useActionSync({ feed: { interval: 15_000 } });

    // @ts-expect-error the hook's blocked is the host's flag itself, not a function
    useActionSync({ blocked: () => true });

    // @ts-expect-error onWaiting is the hook's own: it drives waiting
    useActionSync({ onWaiting: () => {} });

    // Each function it returns is a property, so it may be destructured (above) or passed on unbound; a narrower
    // parameter would fit a method, whose parameters are bivariant.
    type Sync = ReturnType<typeof useActionSync>;

    // @ts-expect-error a narrower parameter never fits a property
    const narrowPart: Sync['onPart'] = (_part: ActionPart & { tag: 1 }) => {};

    // @ts-expect-error a narrower parameter never fits a property
    const narrowTouch: Sync['touch'] = (_keys: readonly 'posts'[]) => {};

    const rows = actionRows(latest);
    const props: ActionActivityProps = { rows };

    // @ts-expect-error rows are ActivityRow values
    const badProps: ActionActivityProps = { rows: [{ label: 'Saving…' }] };

    return (
        <aside>
            <ActionActivity rows={rows.slice(-3)} />
            <ActionActivity {...props} />
            {waiting && (
                <button type="button" onClick={apply}>
                    Refresh
                </button>
            )}
            {String(waitingIsBoolean)}
            {String(options.blocked)}
            {String(badProps)}
            {String(narrowPart)}
            {String(narrowTouch)}
        </aside>
    );
}

export function PostEditor({ isDirty }: { isDirty: boolean }) {
    useActionEdits(isDirty);

    // @ts-expect-error the flag is a boolean
    useActionEdits('dirty');

    return null;
}

// 0.4: one waiting confirmation, answered through useChat's addToolApprovalResponse().
export function Confirmation({ latest, answer }: { latest: { parts: { type: string }[] }; answer: (response: { id: string; approved: boolean }) => void }) {
    const approval = approvalCard(latest);

    expectTrue<Equal<ApprovalCardProps, { approval: WaitingApproval; onAnswer: (approved: boolean) => void }>>();

    // @ts-expect-error onAnswer takes whether the person confirmed
    const badAnswer: ApprovalCardProps['onAnswer'] = (approved: string) => approved;

    // @ts-expect-error the card needs the approval id useChat answers
    const noId: ApprovalCardProps = { approval: { action: 'x', effect: 'destructive', label: '', title: '', summary: [], confirm: '', decline: '' }, onAnswer: () => {} };

    return (
        <>
            {approval && <ApprovalCard approval={approval} onAnswer={(approved) => answer({ id: approval.id, approved })} />}
            {String(badAnswer)}
            {String(noId)}
        </>
    );
}

// 0.5: one waiting form, answered with an ElicitResult; errors by field keep it open.
export function Form({ latest, answer }: { latest: { parts: { type: string }[] }; answer: (result: ElicitResult) => Promise<Record<string, string[]> | null> }) {
    const form = elicitation(latest);

    expectTrue<Equal<Parameters<ElicitationFormProps['onAnswer']>[0], ElicitResult>>();

    // A host's own answer may return nothing, or errors by field.
    const quiet: ElicitationFormProps['onAnswer'] = () => {};
    const checked: ElicitationFormProps['onAnswer'] = async () => ({ body: ['Too long.'] });

    // @ts-expect-error MCP asks a client to show which server asks
    const noSource: ElicitationFormProps['labels'] = { submit: 'Submit', decline: 'Decline', cancel: 'Not now' };

    // @ts-expect-error MCP asks a client to offer cancel beside decline
    const noCancel: ElicitationFormProps['labels'] = { source: 'Asked by Laravel', submit: 'Submit', decline: 'Decline' };

    // @ts-expect-error errors are lists of messages by field
    const badErrors: ElicitationFormProps['onAnswer'] = async () => ({ body: 'Too long.' });

    // @ts-expect-error a class name for a part the form does not have
    const badClass: ElicitationFormProps['classNames'] = { legend: 'text-sm' };

    return (
        <>
            {form && (
                <ElicitationForm
                    key={form.id}
                    params={form.params}
                    labels={form.labels}
                    onAnswer={(result) => answer(result)}
                    classNames={{ form: 'space-y-4', input: 'rounded-md border', submit: 'btn', cancel: 'btn-ghost' }}
                />
            )}
            {String(quiet)}
            {String(checked)}
            {String(noSource)}
            {String(noCancel)}
            {String(badErrors)}
            {String(badClass)}
        </>
    );
}
