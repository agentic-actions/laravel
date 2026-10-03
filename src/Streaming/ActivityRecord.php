<?php

namespace AgenticActions\Streaming;

use AgenticActions\Effect;
use AgenticActions\Outcome;
use AgenticActions\Pipeline\OutcomeKind;
use Throwable;

/**
 * What one tool invocation reported for its row.
 *
 * @internal
 */
final class ActivityRecord
{
    /**
     * Create a record.
     *
     * @param  'done'|'refused'|'failed'  $status
     * @param  list<string>  $touches  what a success made stale; ['*'] means everything on the page
     * @param  array{url: string, follow: bool}|null  $link
     * @param  array<string, mixed>|null  $view  the data-view part of the table a success shows
     */
    public function __construct(
        public readonly string $status,
        public readonly array $touches = [],
        public readonly ?array $link = null,
        public readonly ?array $view = null,
    ) {}

    /**
     * The record of an action's outcome: done with its touches, link and the table it shows, refused, or failed.
     *
     * @param  array<string, mixed>|null  $view
     */
    public static function fromOutcome(Outcome $outcome, ?array $view = null): self
    {
        if (! $outcome->ok()) {
            return new self($outcome->kind() === OutcomeKind::Failed ? 'failed' : 'refused');
        }

        $entry = $outcome->entry();
        $touches = $entry->effect === Effect::Read ? [] : ($entry->touches === [] ? ['*'] : $entry->touches);   // a Write that names nothing reloads the page

        return new self('done', $touches, self::link($outcome), $view);
    }

    /**
     * Failed outranks refused, which outranks done: several reports in one call keep the worst.
     */
    public function rank(): int
    {
        return ['done' => 0, 'refused' => 1, 'failed' => 2][$this->status];
    }

    /**
     * redirectTo()'s URL when it stays on this site, with the action's $followLink; else null. A throwing
     * redirectTo() is reported and gives no link.
     *
     * @return array{url: string, follow: bool}|null
     */
    private static function link(Outcome $outcome): ?array
    {
        try {
            $url = $outcome->entry()->action()->redirectTo($outcome->result(), $outcome->context());
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        // No backslash and no control character: browsers read "/\evil.example" as a host.
        if (! is_string($url) || $url === '' || preg_match('/[\\\\\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        // No "." or ".." segment, raw or encoded: a browser resolves one to another route.
        if ($path === false || preg_match('#(^|/)(\.|%2e){1,2}(/|$)#i', (string) $path) === 1) {
            return null;
        }

        $local = preg_match('#^/(?!/)#', $url) === 1;   // "/path", never "//host"
        $host = parse_url($url, PHP_URL_HOST);

        // A URL with no host of its own ("https:/evil.example") never matches, even when app.url has none either.
        $sameHost = is_string($host) && $host !== '' && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            && $host === parse_url((string) config('app.url'), PHP_URL_HOST);

        return $local || $sameHost ? ['url' => $url, 'follow' => $outcome->entry()->followLink] : null;
    }
}
