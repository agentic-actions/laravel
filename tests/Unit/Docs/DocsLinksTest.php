<?php

use Symfony\Component\Finder\Finder;

/*
 * An app's developer reads the package's pointers to its docs in what it prints (the installer's next steps,
 * actions:check, exception messages), in the published config and stubs, and in Boost's guideline and skill. The app
 * has no docs/ folder, so each pointer is a page of the site, and the page and heading it names exist.
 */

/**
 * The repository root, or one path inside it.
 */
function repositoryPath(string $path = ''): string
{
    return dirname(__DIR__, 3).($path === '' ? '' : '/'.$path);
}

/**
 * What an app's developer reads of each shipped file, by path: the strings of the PHP under src/ and lang/ (their
 * comments are for the package's maintainers), and the whole of config/, stubs/ and resources/.
 *
 * @return array<string, string>
 */
function shippedText(): array
{
    $texts = [];

    foreach (Finder::create()->files()->in(array_map(repositoryPath(...), ['src', 'lang', 'config', 'stubs', 'resources'])) as $file) {
        $path = substr($file->getPathname(), strlen(repositoryPath()) + 1);
        $source = $file->getContents();

        if (preg_match('#^(src|lang)/#', $path) === 1) {
            $strings = array_filter(token_get_all($source), fn (mixed $token): bool => is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true));
            $source = implode("\n", array_map(fn (array $token): string => $token[1], $strings));
        }

        $texts[$path] = $source;
    }

    return $texts;
}

/**
 * The source file of a page of the site, by its path on the site, as docs/.vitepress/config.mts serves them.
 */
function sitePageSource(string $page): ?string
{
    $candidates = str_starts_with($page, 'how-it-works/')
        ? ['docs/site/concepts/'.substr($page, strlen('how-it-works/')).'.md']
        : ["docs/{$page}.md", "docs/site/{$page}.md"];

    foreach ($candidates as $candidate) {
        if (is_file(repositoryPath($candidate))) {
            return $candidate;
        }
    }

    return null;
}

/**
 * The anchors of a Markdown page's headings, slugged as the site slugs them (GitHub's way, a repeated heading taking
 * -1, -2), or as a heading's own {#id} names it. Fenced code is not read.
 *
 * @return list<string>
 */
function headingAnchors(string $markdown): array
{
    $anchors = [];
    $fenced = false;

    foreach (explode("\n", $markdown) as $line) {
        if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
            $fenced = ! $fenced;

            continue;
        }

        if ($fenced || preg_match('/^#{1,6}\s+(.+?)\s*$/', $line, $heading) !== 1) {
            continue;
        }

        if (preg_match('/\{#([\w-]+)\}$/', $heading[1], $custom) === 1) {
            $anchors[] = $custom[1];

            continue;
        }

        $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $heading[1]);
        $slug = str_replace(' ', '-', (string) preg_replace('/[^\p{L}\p{M}\p{N}\p{Pc}\- ]/u', '', mb_strtolower(trim($text))));
        $repeats = count(array_filter($anchors, fn (string $anchor): bool => $anchor === $slug || preg_match('/^'.preg_quote($slug, '/').'-\d+$/', $anchor) === 1));

        $anchors[] = $repeats === 0 ? $slug : "{$slug}-{$repeats}";
    }

    return $anchors;
}

it('points at the site, never at a docs/ file or the docs on GitHub', function () {
    $found = [];

    foreach (shippedText() as $path => $text) {
        preg_match_all('~(?<![\w./-])docs/[\w./-]+\.md(?:#[\w-]*)?|github\.com/agentic-actions/laravel/(?:blob|tree)/[\w.-]+/docs\b[^\s\'")]*~', $text, $matches);

        foreach ($matches[0] as $match) {
            $found[] = "{$path}: {$match}";
        }
    }

    expect($found)->toBe([]);
});

it('links only to pages and headings the site has', function () {
    $links = [];
    $broken = [];

    foreach (shippedText() as $path => $text) {
        preg_match_all('~https://agentic-actions\.com(/[\w/-]*)?(?:#([\w-]+))?~', $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $page = trim($match[1] ?? '', '/');
            $source = sitePageSource($page === '' ? 'index' : $page);
            $links[] = $match[0];

            if ($source === null) {
                $broken[] = "{$path}: {$match[0]} (no page)";
            } elseif (($match[2] ?? '') !== '' && ! in_array($match[2], headingAnchors((string) file_get_contents(repositoryPath($source))), true)) {
                $broken[] = "{$path}: {$match[0]} (no such heading in {$source})";
            }
        }
    }

    expect($links)->toContain('https://agentic-actions.com/concepts#tenants', 'https://agentic-actions.com/mcp#harden-the-oauth-setup')
        ->and($broken)->toBe([]);
});
