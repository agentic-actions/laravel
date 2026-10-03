<?php

namespace AgenticActions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * An action's authored "no": a sentence for the caller, never a crash. Apps may subclass it for domain refusals.
 *
 * @api
 */
class Refusal extends RuntimeException implements ShouldntReport
{
    /**
     * The field the message belongs to, if any.
     */
    private ?string $onField = null;

    /**
     * The HTTP status a JSON caller receives.
     */
    private int $httpStatus = 409;

    /**
     * Extra data for web and API callers only.
     *
     * @var array<string, mixed>
     */
    private array $extra = [];

    /**
     * Text other people wrote, which a model receives framed as data.
     *
     * @var list<mixed>|null
     */
    private ?array $items = null;

    /**
     * Create a refusal. Use make().
     *
     * @param  array<string, string|int|float>  $replace
     */
    final protected function __construct(
        private readonly string $translationKey,
        private readonly ?string $default = null,
        private readonly array $replace = [],
    ) {
        parent::__construct($default ?? $translationKey);
    }

    /**
     * Refuse with a translation key, an optional default sentence and replacements. Replacements must be authored or
     * catalogue text, or the actor's own saved rows, never the caller's input.
     *
     * @param  array<string, string|int|float>  $replace
     */
    public static function make(string $key, ?string $default = null, array $replace = []): static
    {
        return new static($key, $default, $replace);
    }

    /**
     * Put the message on one field (an index path such as "items.3" names an item without echoing it).
     */
    public function on(string $field): static
    {
        $this->onField = $field;

        return $this;
    }

    /**
     * The HTTP status a JSON caller receives. Default 409.
     */
    public function status(int $status): static
    {
        $this->httpStatus = $status;

        return $this;
    }

    /**
     * Extra data for web and API callers only. Never sent to a model.
     *
     * @param  array<string, mixed>  $details
     */
    public function details(array $details): static
    {
        $this->extra = $details;

        return $this;
    }

    /**
     * Text other people wrote, which a model receives framed as data after "---".
     *
     * @param  list<mixed>  $items
     */
    public function listing(array $items): static
    {
        $this->items = array_values($items);

        return $this;
    }

    /**
     * The translation key, also the JSON "code".
     */
    public function key(): string
    {
        return $this->translationKey;
    }

    /**
     * The field the message belongs to, if any.
     */
    public function field(): ?string
    {
        return $this->onField;
    }

    /**
     * The HTTP status for JSON callers.
     */
    public function statusCode(): int
    {
        return $this->httpStatus;
    }

    /**
     * The web and API details.
     *
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->extra;
    }

    /**
     * The listing, if any.
     *
     * @return list<mixed>|null
     */
    public function getListing(): ?array
    {
        return $this->items;
    }

    /**
     * The message in the given locale, through Actions::translateUsing() when set, else Laravel's translator.
     *
     * @throws InvalidArgumentException when the locale could name a directory outside the language directories
     */
    public function translate(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        // The translator reads files from a directory the locale names: refuse one that could leave the language
        // directories, as Laravel's own setLocale() does.
        if (str_contains($locale, '/') || str_contains($locale, '\\')) {
            throw new InvalidArgumentException('Invalid characters present in locale.');
        }

        $translator = str_starts_with($this->translationKey, 'agentic-actions::')
            ? null
            : app(ActionsManager::class)->translator();

        if ($translator !== null) {
            return (string) $translator($this->translationKey, $this->default, $this->replace, $locale);
        }

        $line = Lang::has($this->translationKey, $locale)
            ? trans($this->translationKey, $this->replace, $locale)
            : trans($this->default ?? $this->translationKey, $this->replace, $locale);

        return is_string($line) ? $line : $this->translationKey;
    }

    /**
     * A ValidationException carrying the message on its field, or on "action".
     */
    public function toValidationException(string $errorBag = 'default', ?string $locale = null): ValidationException
    {
        return ValidationException::withMessages([
            $this->onField ?? 'action' => [$this->translate($locale)],
        ])->errorBag($errorBag);
    }
}
