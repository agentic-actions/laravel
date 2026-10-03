<?php

use Illuminate\Support\Arr;
use Symfony\Component\Finder\Finder;

/**
 * The package's lang directory, or one path inside it.
 */
function packageLangPath(string $path = ''): string
{
    return dirname(__DIR__, 3).'/lang'.($path === '' ? '' : '/'.$path);
}

/**
 * Every shipped locale.
 *
 * @return list<string>
 */
function shippedLocales(): array
{
    $locales = array_map('basename', glob(packageLangPath('*'), GLOB_ONLYDIR) ?: []);

    sort($locales);

    return $locales;
}

/**
 * One locale's lines, by file and key; a group of lines, such as oauth.abilities, flattens to dotted keys.
 *
 * @return array<string, array<string, mixed>>
 */
function localeLines(string $locale): array
{
    $lines = [];

    foreach (glob(packageLangPath($locale.'/*.php')) ?: [] as $file) {
        $lines[basename($file, '.php')] = Arr::dot(require $file);
    }

    ksort($lines);

    return $lines;
}

/**
 * The :placeholders in one line, sorted and unique.
 *
 * @return list<string>
 */
function linePlaceholders(string $line): array
{
    preg_match_all('/(?<![A-Za-z0-9_:]):([A-Za-z][A-Za-z0-9_]*)/', $line, $matches);

    $names = array_values(array_unique($matches[1]));

    sort($names);

    return $names;
}

/**
 * Every package key the source names: literal "agentic-actions::group.key" strings, and the "group.key" lines the
 * model sentences build their keys from.
 *
 * @return list<string>
 */
function sourceLangKeys(): array
{
    $keys = [];

    foreach (Finder::create()->files()->name('*.php')->in(dirname(__DIR__, 3).'/src') as $file) {
        $source = $file->getContents();

        preg_match_all('/agentic-actions::([a-z_]+\.[a-z_]+(?:\.[a-z_]+)*)/', $source, $literal);
        preg_match_all("/line\\('([a-z_]+\\.[a-z_]+)'/", $source, $built);

        array_push($keys, ...$literal[1], ...$built[1]);
    }

    $keys = array_values(array_unique($keys));

    sort($keys);

    return $keys;
}

it('ships English and Arabic', function () {
    expect(shippedLocales())->toContain('en', 'ar');
});

it('gives every locale exactly English\'s files and keys', function (string $locale) {
    $english = localeLines('en');
    $lines = localeLines($locale);

    expect(array_keys($lines))->toBe(array_keys($english));

    foreach ($english as $file => $keys) {
        expect(array_keys($lines[$file]))->toEqualCanonicalizing(array_keys($keys), "{$locale}/{$file}.php has other keys than en/{$file}.php.");
    }
})->with(fn (): array => shippedLocales());

it('keeps each line flat, filled and with English\'s placeholders', function (string $locale) {
    foreach (localeLines('en') as $file => $keys) {
        $lines = localeLines($locale)[$file];

        foreach ($keys as $key => $english) {
            expect($english)->toBeString()
                ->and($lines[$key])->toBeString()->not->toBe('')
                ->and(linePlaceholders($lines[$key]))->toBe(linePlaceholders($english), "{$locale}/{$file}.php [{$key}] has other placeholders than English.");
        }
    }
})->with(fn (): array => shippedLocales());

it('finds placeholders and nothing else', function () {
    expect(linePlaceholders('Not done. Rejected: :fields.'))->toBe(['fields'])
        ->and(linePlaceholders('لم يتم. رُفضت الحقول: :fields.'))->toBe(['fields'])
        ->and(linePlaceholders('Not done: that action is not available here.'))->toBe([])
        ->and(linePlaceholders('At 10:30, :name and :count, then :name again.'))->toBe(['count', 'name']);
});

it('has an English line for every package key the source uses', function () {
    $keys = sourceLangKeys();
    $english = localeLines('en');

    expect($keys)->toContain('http.not_found', 'http.denied', 'http.invalid', 'model.done', 'model.rejected');

    // A key built from a group, such as agentic-actions::oauth.abilities.{effect}, names the group.
    foreach ($keys as $key) {
        [$file, $line] = explode('.', $key, 2);
        $group = array_filter(array_keys($english[$file] ?? []), fn (string $name): bool => str_starts_with($name, $line.'.'));

        expect(is_string($english[$file][$line] ?? null) || $group !== [])->toBeTrue("src/ uses agentic-actions::{$key}, which lang/en does not define.");
    }
});
