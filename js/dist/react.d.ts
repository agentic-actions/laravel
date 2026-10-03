import { type ReactElement } from 'react';
import { useHttp } from '@inertiajs/react';
import { type FormDataType, type UrlMethodPair, type UseHttpSubmitOptions } from '@inertiajs/core';
import { type ActionDefinition, type ActionPart, type ActionSyncOptions, type ActivityRow, type ElicitationData, type ElicitationParams, type ElicitResult, type WaitingApproval } from '@agentic-actions/client';
export type UseActionOptions = {
    idempotencyKey?: string;
};
/** Each call signature of an overloaded function (up to eight), as [parameters, return type]. */
type Signatures<F> = F extends {
    (...args: infer A1): infer R1;
    (...args: infer A2): infer R2;
    (...args: infer A3): infer R3;
    (...args: infer A4): infer R4;
    (...args: infer A5): infer R5;
    (...args: infer A6): infer R6;
    (...args: infer A7): infer R7;
    (...args: infer A8): infer R8;
} ? [A1, R1] | [A2, R2] | [A3, R3] | [A4, R4] | [A5, R5] | [A6, R6] | [A7, R7] | [A8, R8] : never;
/** The return type of the signature that takes a URL-and-method pair and the data. */
type PairResult<S> = S extends [[infer First, unknown], infer R] ? (string extends First ? never : UrlMethodPair extends First ? R : never) : never;
/**
 * useHttp's precognitive result for a URL-and-method pair. @inertiajs/react does not export that type by name, so it is
 * read from useHttp's own signatures, which keeps the declaration file free of paths into the package.
 */
type PairHttp<I extends FormDataType<I>, O> = PairResult<Signatures<typeof useHttp<I, O>>>;
/**
 * Inertia's useHttp for an action: the same errors, processing and precognitive validate(), plus run() and refusal.
 * run() resolves the output, or undefined when refused: field errors land on errors, anything else on refusal.
 * validate() does the same with a 401, 403, 404, 409 or 423, so every status settles the check.
 */
export declare function useAction<I extends FormDataType<I>, O>(definition: ActionDefinition<I, O>, initial: I, options?: UseActionOptions): PairHttp<I, O> & {
    run: (submit?: UseHttpSubmitOptions<O, I>) => Promise<O | undefined>;
    refusal: string | null;
};
export type UseActionSyncOptions = Omit<ActionSyncOptions, 'blocked' | 'onWaiting'> & {
    /** The host's own unsaved-work flag. A held refresh runs by itself once it and every editor are clean. */
    blocked?: boolean;
};
/**
 * One coalesced refresh per burst of done rows, held while an editor is dirty, and run by itself once none is, unless
 * resumeWhenClean is false. Default apply: inertiaApply. It reads the data-action parts an actionsChat() Chat emits; a
 * host with its own reader calls onPart() itself. when, how, apply, resumeWhenClean and feed are read at mount: a page
 * whose feed URL changes remounts the component that calls it (key={feedUrl}). Every function it returns can be
 * destructured or passed on unbound.
 */
export declare function useActionSync(options?: UseActionSyncOptions): {
    waiting: boolean;
    apply: () => void;
    flush: () => void;
    onPart: (part: ActionPart) => void;
    touch: (keys: readonly string[]) => void;
};
/** Register an editor: while it is dirty, every copilot refresh and follow waits, and useActionSync's waiting turns true. */
export declare function useActionEdits(dirty: boolean): void;
export type ActionActivityProps = {
    rows: readonly ActivityRow[];
};
/** Rows, unstyled and accessible. A host shows fewer with rows.slice(-3), and styles through the data-* attributes. */
export declare function ActionActivity({ rows }: ActionActivityProps): ReactElement | null;
export type ApprovalCardProps = {
    approval: WaitingApproval;
    onAnswer: (approved: boolean) => void;
};
/** One waiting confirmation, unstyled: the server's sentence and rows, and two buttons. onAnswer(true) confirms. */
export declare function ApprovalCard({ approval, onAnswer }: ApprovalCardProps): ReactElement;
/**
 * Any MCP form-mode elicitation: text by format (one the standard does not list is plain text), numbers, yes or no, and
 * one choice or several, titled, untitled or legacy (enumNames), as native controls, with the browser's own checks in
 * the person's language and the server's errors under their fields. A select opens on an empty option, so a required
 * one never sends a choice nobody made; no checkbox carries required, which in HTML means checked; a boolean always
 * answers true or false, a list [] unless it may be left out, and a date-time opens in local time and answers in UTC.
 */
export type ElicitationFormProps = {
    params: ElicitationParams;
    /** All four required: MCP asks a client to show which server asks, and to offer both decline and cancel. */
    labels: ElicitationData['labels'];
    /**
     * The answer. Resolving to errors by field keeps the form open with them shown, and a rejection lets the person
     * answer again; anything else ends it, its buttons disabled until the host drops it, as elicitation() does once the
     * call is answered.
     */
    onAnswer: (result: ElicitResult) => void | Promise<Record<string, string[]> | null | void>;
    /** Class names for the form's parts, for Tailwind or shadcn/ui classes. */
    classNames?: Partial<Record<'form' | 'source' | 'message' | 'field' | 'label' | 'input' | 'description' | 'error' | 'actions' | 'submit' | 'decline' | 'cancel', string>>;
};
/** Any MCP form-mode elicitation as an accessible form. */
export declare function ElicitationForm({ params, labels, onAnswer, classNames }: ElicitationFormProps): ReactElement;
export {};
