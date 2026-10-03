<?php

namespace Tests\Feature\Security;

use AgenticActions\ActionContext;
use AgenticActions\Refusal;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Workbench\App\Models\User;

/*
 * A locale names a directory the translator reads language files from. Laravel's own setLocale() refuses one that
 * could leave the language directories; every locale the package hands the translator is refused the same way.
 */

beforeEach(function () {
    Auth::shouldUse('web');

    $this->user = User::factory()->create();
    $this->probe = sys_get_temp_dir().'/agentic-actions-locale-'.getmypid();

    unset($GLOBALS['agentic_actions_locale_probe']);
    File::ensureDirectoryExists($this->probe);

    foreach (['model', 'http'] as $group) {
        File::put("{$this->probe}/{$group}.php", '<?php $GLOBALS[\'agentic_actions_locale_probe\'] = true; return [];');
    }
});

afterEach(function () {
    File::deleteDirectory($this->probe);
    unset($GLOBALS['agentic_actions_locale_probe']);
});

/**
 * A locale that walks from the package's language directory to the probe directory.
 */
function probeLocale(string $probe): string
{
    $lang = (string) realpath(dirname(__DIR__, 3).'/lang');

    return str_repeat('../', substr_count($lang, '/')).ltrim((string) realpath($probe), '/');
}

it('refuses a locale that could leave the language directories, on every context', function (Closure $build) {
    expect(fn () => $build(test()->user, 'en/../../x'))->toThrow(InvalidArgumentException::class, 'Invalid characters present in locale.')
        ->and(fn () => $build(test()->user, 'en\\x'))->toThrow(InvalidArgumentException::class);
})->with([
    'agent()' => fn (User $user, string $locale) => ActionContext::agent($user, null, $locale),
    'http()' => fn (User $user, string $locale) => ActionContext::http($user, null, $locale),
    'system()' => fn (User $user, string $locale) => ActionContext::system(null, $locale),
    'console()' => fn (User $user, string $locale) => ActionContext::console($user, null, $locale, null),
]);

it('refuses such a locale when a refusal is translated for the app', function () {
    expect(fn () => Refusal::make('agentic-actions::http.denied')->translate('../../x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Refusal::make('Not yours.')->toValidationException('default', '..\\x'))->toThrow(InvalidArgumentException::class);
});

it('never loads a language file from the directory a locale walks to', function () {
    $locale = probeLocale($this->probe);

    // The control: whether the translator, handed this locale straight, reads the probe. Where it does, the last line
    // below can only hold because the package refused the locale first; where the translator's own loader refuses it
    // too, the two throws are the package's proof.
    app('translator')->get('agentic-actions::model.failed', [], $locale);
    $translatorReadsIt = $GLOBALS['agentic_actions_locale_probe'] ?? false;
    unset($GLOBALS['agentic_actions_locale_probe']);

    expect(fn () => ActionContext::agent($this->user, null, $locale))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Refusal::make('agentic-actions::http.denied')->translate($locale))->toThrow(InvalidArgumentException::class);

    if ($translatorReadsIt) {
        expect($GLOBALS['agentic_actions_locale_probe'] ?? false)->toBeFalse();
    }
});
