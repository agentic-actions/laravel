<?php

namespace AgenticActions\Approvals;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Exposure\Entry;
use Illuminate\Support\ValidatedInput;
use JsonException;
use LogicException;

/**
 * What a person confirms for one Destructive or External agent call, built on the server by Runner::preview().
 *
 * @internal
 */
final class ApprovalCard
{
    /**
     * The most rows a card shows.
     */
    public const MAX_ROWS = 8;

    /**
     * The most characters of a title, label or value.
     */
    public const MAX_TEXT = 200;

    /**
     * Create a card.
     *
     * @param  list<array{label: string, value: string}>  $summary
     */
    public function __construct(
        public readonly Entry $entry,
        public readonly ActionContext $context,
        public readonly string $title,
        public readonly array $summary,
        public readonly string $fingerprint,
    ) {}

    /**
     * Build the card from the action's authored methods, in the context's locale, after both authorize steps.
     *
     * @throws LogicException when approvalSummary() returns more than MAX_ROWS rows or a value that is not a string or number
     */
    public static function build(Entry $live, Action $action, ActionContext $context, ValidatedInput $input): self
    {
        $rows = $action->approvalSummary($context, $input);

        if (count($rows) > self::MAX_ROWS) {
            throw new LogicException("{$live->class}: approvalSummary() returned ".count($rows).' rows; a card shows at most '.self::MAX_ROWS.'.');
        }

        $summary = [];

        foreach ($rows as $label => $value) {
            if ($value === null) {
                continue;
            }

            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                throw new LogicException("{$live->class}: approvalSummary() row [{$label}] is ".get_debug_type($value).'; a card shows strings and numbers only.');
            }

            $summary[] = ['label' => self::text((string) $label), 'value' => self::text((string) $value)];
        }

        $title = $action->approvalReason($context)
            ?? (string) trans('agentic-actions::approval.'.$live->effect?->value, [], $context->locale);

        return new self($live, $context, self::text($title), $summary, ApprovalClaims::fingerprint($input));
    }

    /**
     * The card as the data-approval part for this call.
     *
     * @return array{type: 'data-approval', id: string, data: array{action: string, effect: string, label: string, title: string, summary: list<array{label: string, value: string}>, confirm: string, decline: string}}
     */
    public function part(string $toolCallId): array
    {
        $locale = $this->context->locale;

        return [
            'type' => 'data-approval',
            'id' => 'approval:'.$toolCallId,
            'data' => [
                'action' => $this->entry->name,
                'effect' => (string) $this->entry->effect?->value,
                'label' => (string) trans('agentic-actions::approval.waiting', [], $locale),
                'title' => $this->title,
                'summary' => $this->summary,
                'confirm' => (string) trans('agentic-actions::approval.confirm', [], $locale),
                'decline' => (string) trans('agentic-actions::approval.decline', [], $locale),
            ],
        ];
    }

    /**
     * The row sent instead of the card when this call can never be confirmed (no claim could be minted).
     *
     * @return array{type: 'data-action', id: string, data: array<string, string>}
     */
    public function refusedRow(string $toolCallId): array
    {
        return [
            'type' => 'data-action',
            'id' => 'a:'.$toolCallId,
            'data' => [
                'action' => $this->entry->name,
                'label' => $this->title,
                'status' => 'refused',
                'effect' => (string) $this->entry->effect?->value,
                'note' => (string) trans('agentic-actions::activity.refused', [], $this->context->locale),
            ],
        ];
    }

    /**
     * What a claim binds for this card: the action, actorKey(), tenantKey(), the conversation, the call, the
     * fingerprint and the card's text, one per line.
     *
     * @throws JsonException never in practice: the card's text is valid UTF-8
     */
    public function binding(string $conversationId, string $toolCallId): string
    {
        return implode("\n", [
            $this->entry->name, $this->context->actorKey(), $this->context->tenantKey(), $conversationId, $toolCallId, $this->fingerprint,
            json_encode([$this->title, $this->summary], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Plain text for a card, a form or a consent screen: valid UTF-8, trimmed, at most MAX_TEXT characters. Control
     * characters, line and paragraph separators, the direction embeddings, overrides and isolates, and the direction
     * marks (U+200E, U+200F, U+061C) become spaces, so no value breaks a line or reorders the text around it.
     *
     * @internal
     */
    public static function text(string $text): string
    {
        $text = (string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}\x{061C}]/u', ' ', mb_scrub($text, 'UTF-8'));

        return mb_substr(trim($text), 0, self::MAX_TEXT);
    }
}
