<?php

namespace AgenticActions;

/**
 * The app's answer, from GovernsModelCalls, for one model-driven call: run it alone, wait for a person's confirmation
 * on a card, or refuse it with a sentence. The sentence of a confirmation is the app's rule as the person reads it on
 * the card ("Your organization asks before publishing."), beside the action's own; a refusal's sentence is what the
 * model reads, so keep both authored, without the caller's input.
 *
 * @api
 */
final class ModelCallRule
{
    public const RUN = 'run';

    public const CONFIRM = 'confirm';

    public const REFUSE = 'refuse';

    /**
     * Use run(), confirm() or refuse().
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?string $sentence = null,
    ) {}

    /**
     * The model may run the call alone. On the agent door this lifts the confirmation of a Destructive or External
     * action; MCP still never serves one, and a queued run still needs its card.
     */
    public static function run(): self
    {
        return new self(self::RUN);
    }

    /**
     * Each call waits for a person's confirmation on a card, whatever the action's effect. Where no person can confirm
     * (MCP, an agent that cannot pause, a queued run) the call is refused, with the package's sentence.
     */
    public static function confirm(?string $rule = null): self
    {
        return new self(self::CONFIRM, $rule === null || trim($rule) === '' ? null : $rule);
    }

    /**
     * The call is refused, and the model reads this sentence. The action stays listed, so the model can say why.
     */
    public static function refuse(string $sentence): self
    {
        return new self(self::REFUSE, $sentence);
    }

    public function runs(): bool
    {
        return $this->kind === self::RUN;
    }

    public function confirms(): bool
    {
        return $this->kind === self::CONFIRM;
    }

    public function refuses(): bool
    {
        return $this->kind === self::REFUSE;
    }
}
