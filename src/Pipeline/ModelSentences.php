<?php

namespace AgenticActions\Pipeline;

use AgenticActions\Effect;
use AgenticActions\Outcome;

/**
 * The sentences a model reads. A model never receives exception messages, validator messages (unless the action opts
 * in), refusal details or submitted values.
 *
 * @internal
 */
final class ModelSentences
{
    /**
     * The JSON flags for data a model reads after "---".
     */
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * The sentence a model reads for an outcome; $shown when the person sees this call's table.
     */
    public static function for(Outcome $outcome, bool $shown = false): string
    {
        $locale = $outcome->context()->locale;

        return match ($outcome->kind()) {
            OutcomeKind::Ok => $shown && $outcome->entry()->shows() ? self::table($outcome, $locale) : self::ok($outcome, $locale),
            OutcomeKind::Invalid => self::line('model.rejected', $locale, ['fields' => self::fields($outcome)]),
            OutcomeKind::NotFound => self::line('model.not_found', $locale),
            OutcomeKind::Denied => self::line('model.denied', $locale),
            OutcomeKind::Refused => self::refused($outcome, $locale),
            OutcomeKind::Failed => self::line('model.failed', $locale),
        };
    }

    /**
     * The success sentence: the reply, or "Done."; a Read adds its output as data after "---".
     */
    private static function ok(Outcome $outcome, string $locale): string
    {
        if ($outcome->entry()->effect !== Effect::Read) {
            return $outcome->modelReply() ?? self::line('model.done', $locale);
        }

        return ($outcome->modelReply() ?? self::line('model.found', $locale))
            ."\n---\n"
            .json_encode($outcome->output() ?? [], self::JSON);
    }

    /**
     * The compact copy of a table the person sees: that they see it and how many rows it holds, and the hint; then as
     * data after "---" the caption, the columns' keys, labels and descriptions, the first views.model_rows rows with each
     * text cut to 80 characters, and whether the table was cut. A dataset's caption repeats the call's filter values, so
     * it is data, never one of the package's own lines.
     */
    private static function table(Outcome $outcome, string $locale): string
    {
        $output = $outcome->output() ?? [];
        $rows = array_values((array) ($output['rows'] ?? []));
        $count = ['count' => (string) count($rows)];
        $truncated = ($output['truncated'] ?? false) === true;

        $lines = array_filter([
            $outcome->modelReply() ?? self::line('model.table_shown', $locale, $count),
            $truncated ? self::line('model.table_truncated', $locale, $count) : null,
            self::line('model.table_hint', $locale),
        ], is_string(...));

        $copy = [
            ...(is_string($output['caption'] ?? null) ? ['caption' => $output['caption']] : []),
            'columns' => array_map(fn (mixed $column): array => array_intersect_key((array) $column, ['key' => true, 'label' => true, 'description' => true]), (array) ($output['columns'] ?? [])),
            'rows' => array_map(fn (mixed $row): array => array_map(
                fn (mixed $cell): mixed => is_string($cell) && mb_strlen($cell) > 80 ? mb_substr($cell, 0, 79).'…' : $cell,
                (array) $row,
            ), array_slice($rows, 0, (int) config('agentic-actions.views.model_rows', 20))),
            'truncated' => $truncated,
        ];

        return implode("\n", $lines)."\n---\n".json_encode($copy, self::JSON);
    }

    /**
     * The refusal's own sentence, with its listing framed as data after "---". Details never reach a model.
     */
    private static function refused(Outcome $outcome, string $locale): string
    {
        $refusal = $outcome->refusal();

        if ($refusal === null) {
            return self::line('model.failed', $locale);
        }

        $sentence = $refusal->translate($locale);
        $listing = $refusal->getListing();

        return $listing === null ? $sentence : $sentence."\n---\n".json_encode($listing, self::JSON);
    }

    /**
     * The rejected fields: "key (rule, rule)" per key, "key" for a key with no failed rule, or "key: message" when the
     * action sends its messages to a model, never for a run carrying a form's answer: the value refused is the person's.
     */
    private static function fields(Outcome $outcome): string
    {
        $messagesToModel = $outcome->entry()->validationMessagesToModel && $outcome->context()->approval?->form === null;
        $rules = $outcome->failedRules();
        $fields = [];

        foreach ($outcome->errors() as $key => $messages) {
            $names = $rules[$key] ?? [];

            $fields[] = match (true) {
                $names !== [] => $key.' ('.implode(', ', $names).')',
                $messagesToModel && isset($messages[0]) => $key.': '.$messages[0],
                default => (string) $key,
            };
        }

        return implode(', ', $fields);
    }

    /**
     * One of the package's model lines in the given locale.
     *
     * @param  array<string, string>  $replace
     */
    private static function line(string $key, string $locale, array $replace = []): string
    {
        $line = trans('agentic-actions::'.$key, $replace, $locale);

        return is_string($line) ? $line : $key;
    }
}
