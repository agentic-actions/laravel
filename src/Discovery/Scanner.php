<?php

namespace AgenticActions\Discovery;

use AgenticActions\Action;
use AgenticActions\Attributes\DeferToolset;
use AgenticActions\Attributes\UseToolset;
use AgenticActions\Attributes\WithPageContext;
use AgenticActions\Exceptions\DuplicateActionName;
use AgenticActions\Exposure\Entry;
use AgenticActions\Support\Paths;
use Composer\Autoload\ClassLoader;
use PhpToken;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Finds actions and agents. A file is read as text first and loaded only when it names the package, and its class
 * comes from its own tokens, never from its path.
 *
 * @internal
 */
final class Scanner
{
    /**
     * How deep the walk through a class's parents and traits goes before it stops looking.
     */
    private const MAX_DEPTH = 32;

    /**
     * Find actions and agents under the paths, plus the listed classes.
     *
     * @param  list<string>  $paths  relative or absolute; globs allowed; missing paths skipped
     * @param  list<class-string>  $classes
     *
     * @throws DuplicateActionName
     */
    public function scan(array $paths, array $classes = []): Scan
    {
        $directories = $this->directories($paths);
        $candidates = array_map(fn (string $class): string => ltrim($class, '\\'), $classes);
        $sources = [];

        foreach ($this->sources($directories) as $source) {
            if (($class = self::declaredClass($source)) !== null) {
                $candidates[] = $class;
                $sources[$class] = $source;
            }
        }

        $candidates = array_values(array_unique($candidates));

        sort($candidates, SORT_STRING);

        $actions = [];
        $segments = [];
        $agents = [];
        $deferred = [];
        $pageContext = [];
        $warnings = [];

        foreach ($candidates as $class) {
            // A missing trait anywhere in a class's ancestry stops PHP outright when the class loads (before PHP 8.5),
            // so such a class is skipped first.
            if (($missing = self::missingTrait($class, source: $sources[$class] ?? null)) !== null) {
                $warnings[] = "{$class} could not be loaded: Trait \"{$missing}\" not found";

                continue;
            }

            // A class that extends a missing class or implements a missing interface throws from the autoloader.
            try {
                if (! class_exists($class)) {
                    continue;
                }
            } catch (Throwable $exception) {
                $warnings[] = "{$class} could not be loaded: ".self::firstLine($exception->getMessage());

                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            if ($reflection->isSubclassOf(Action::class)) {
                /** @var class-string<Action> $class */
                $entry = Entry::fromClass($class);
                $segment = $entry->segment();

                if (isset($actions[$entry->name])) {
                    throw DuplicateActionName::between($actions[$entry->name]->class, $class, $entry->name);
                }

                if (isset($segments[$segment])) {
                    throw DuplicateActionName::between($segments[$segment], $class, $segment);
                }

                $actions[$entry->name] = $entry;
                $segments[$segment] = $class;
            } else {
                $loaded = $reflection->getAttributes(UseToolset::class)[0] ?? null;
                $searched = $reflection->getAttributes(DeferToolset::class)[0] ?? null;

                // An agent may carry #[DeferToolset] alone: it loads no toolset.
                if ($loaded !== null || $searched !== null) {
                    $agents[$class] = $loaded?->newInstance()->names ?? [];
                }

                if ($searched !== null) {
                    $deferred[$class] = $searched->newInstance()->names;
                }
            }

            // An agent may carry #[WithPageContext] without #[UseToolset].
            if ($reflection->getAttributes(WithPageContext::class) !== []) {
                $pageContext[] = $class;
            }
        }

        // A stable order keeps the tool block byte-identical, so a provider's prompt cache survives.
        ksort($actions, SORT_STRING);
        ksort($agents, SORT_STRING);
        ksort($deferred, SORT_STRING);
        sort($pageContext, SORT_STRING);

        return new Scan($actions, $agents, $directories, $warnings, $pageContext, $deferred);
    }

    /**
     * The first declared class in a PHP source, from its tokens, never from its path.
     */
    public static function declaredClass(string $source): ?string
    {
        $tokens = PhpToken::tokenize($source);
        $namespace = '';

        foreach ($tokens as $index => $token) {
            if ($token->is(T_NAMESPACE)) {
                $name = self::neighbour($tokens, $index, 1);

                $namespace = $name !== null && $name->is([T_NAME_QUALIFIED, T_STRING]) ? $name->text : '';

                continue;
            }

            if (! $token->is(T_CLASS) || self::neighbour($tokens, $index, -1)?->is([T_DOUBLE_COLON, T_NEW]) === true) {
                continue;
            }

            $name = self::neighbour($tokens, $index, 1);

            // An anonymous class behind a modifier ("new readonly class") has no name after the keyword.
            if ($name !== null && $name->is(T_STRING)) {
                return ltrim($namespace.'\\'.$name->text, '\\');
            }
        }

        return null;
    }

    /**
     * The parent and the traits of the first class or trait a source declares, fully qualified, from the file's own
     * tokens: the name after "extends" and the body's use statements, resolved against the file's namespace and imports.
     *
     * @return array{0: ?string, 1: list<string>}
     */
    private static function declaration(string $source): array
    {
        $tokens = PhpToken::tokenize($source);
        $namespace = '';
        $imports = [];
        $parent = null;
        $traits = [];
        $depth = 0;
        $body = null;

        for ($index = 0; isset($tokens[$index]); $index++) {
            $token = $tokens[$index];

            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->text === '}') {
                if ($depth-- === $body) {
                    break;
                }
            } elseif ($token->is(T_NAMESPACE) && $depth === 0) {
                $name = self::neighbour($tokens, $index, 1);
                $namespace = $name !== null && $name->is([T_NAME_QUALIFIED, T_STRING]) ? $name->text : '';
            } elseif ($token->is([T_CLASS, T_TRAIT]) && $body === null && self::neighbour($tokens, $index, -1)?->is([T_DOUBLE_COLON, T_NEW]) !== true && self::neighbour($tokens, $index, 1)?->is(T_STRING) === true) {
                $body = $depth + 1;
            } elseif ($token->is(T_EXTENDS) && $body === $depth + 1 && ($name = self::neighbour($tokens, $index, 1)) !== null) {
                $parent = self::resolve($name->text, $namespace, $imports);
            } elseif ($token->is(T_USE) && ($depth === 0 || $depth === $body)) {
                [$names, $index] = self::useStatement($tokens, $index + 1);

                foreach ($names as [$name, $alias]) {
                    if ($depth === 0) {
                        $imports[strtolower($alias ?? substr((string) strrchr('\\'.$name, '\\'), 1))] = ltrim($name, '\\');
                    } else {
                        $traits[] = self::resolve($name, $namespace, $imports);
                    }
                }

                // A trait block ("use A { … }") opens on the token the statement stopped at.
                $index--;
            }
        }

        return [$parent, array_values(array_unique($traits))];
    }

    /**
     * The names one use statement lists, each with its alias, and the index of the ";" or "{" that ends it. Function
     * and constant imports list nothing.
     *
     * @param  list<PhpToken>  $tokens
     * @return array{0: list<array{0: string, 1: ?string}>, 1: int}
     */
    private static function useStatement(array $tokens, int $index): array
    {
        $names = [];
        $prefix = '';
        $current = null;
        $group = false;
        $skip = self::neighbour($tokens, $index - 1, 1)?->is([T_FUNCTION, T_CONST]) === true;

        for (; isset($tokens[$index]); $index++) {
            $token = $tokens[$index];

            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE]) && $current !== null) {
                $current[1] = $token->text;   // the alias after "as"
            } elseif ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
                $current = [$prefix.$token->text, null];
            } elseif ($token->is(T_NS_SEPARATOR) && $current !== null) {
                $prefix = $current[0].'\\';
                $current = null;
            } elseif ($token->text === '{' && $prefix !== '' && ! $group) {
                $group = true;
            } elseif ($token->text === ',' || $token->text === '}' || $token->text === ';' || $token->text === '{') {
                if ($current !== null) {
                    $names[] = $current;
                    $current = null;
                }

                if ($token->text === '}') {
                    $group = false;
                    $prefix = '';
                } elseif ($token->text === ';' || $token->text === '{') {
                    break;
                }
            }
        }

        return [$skip ? [] : $names, $index];
    }

    /**
     * A name used in the class body, fully qualified against the file's namespace and imports.
     *
     * @param  array<string, string>  $imports
     */
    private static function resolve(string $name, string $namespace, array $imports): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        if (str_starts_with(strtolower($name), 'namespace\\')) {
            return ltrim($namespace.'\\'.substr($name, 10), '\\');
        }

        $parts = explode('\\', $name, 2);
        $import = $imports[strtolower($parts[0])] ?? null;

        if ($import !== null) {
            return isset($parts[1]) ? $import.'\\'.$parts[1] : $import;
        }

        return ltrim($namespace.'\\'.$name, '\\');
    }

    /**
     * The first trait no autoloader can find in a class's ancestry, or null: the class's traits, the traits those use,
     * and the same for each parent, read from tokens before anything loads. Before PHP 8.5 a missing trait anywhere
     * there stops PHP with a fatal error no catch sees. A missing parent class throws instead, which the scan catches,
     * so the walk ends there. A trait that uses itself, directly or through others, is never found either.
     *
     * @param  ?string  $source  the class's own source, when the scan has read it
     * @param  list<string>  $path  the classes and traits the walk came through, lowercased
     */
    private static function missingTrait(string $name, bool $trait = false, ?string $source = null, array $path = []): ?string
    {
        if (in_array(strtolower($name), $path, true)) {
            return $trait ? $name : null;
        }

        // A class or trait already declared was linked when it loaded, so everything it uses exists.
        if (count($path) >= self::MAX_DEPTH || ($trait ? trait_exists($name, false) : class_exists($name, false))) {
            return null;
        }

        if (($source ??= self::composerSource($name)) === null) {
            // Composer has no file: another autoloader may still declare a trait, and a missing class throws later.
            try {
                return $trait && ! trait_exists($name) ? $name : null;
            } catch (Throwable) {
                return $name;
            }
        }

        [$parent, $traits] = self::declaration($source);
        $path[] = strtolower($name);

        foreach ($traits as $used) {
            if (($missing = self::missingTrait($used, trait: true, path: $path)) !== null) {
                return $missing;
            }
        }

        return $parent === null ? null : self::missingTrait($parent, path: $path);
    }

    /**
     * The source of the file Composer would load for a class or trait, or null.
     */
    private static function composerSource(string $name): ?string
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $file = $loader->findFile($name);

            if (is_string($file) && is_file($file)) {
                return (string) file_get_contents($file);
            }
        }

        return null;
    }

    /**
     * The directories the paths name: absolute paths as they are, relative ones under the base path, globs expanded,
     * missing ones skipped.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function directories(array $paths): array
    {
        $directories = [];

        foreach ($paths as $path) {
            foreach (glob(Paths::resolve($path), GLOB_ONLYDIR) ?: [] as $directory) {
                $directories[] = $directory;
            }
        }

        return array_values(array_unique($directories));
    }

    /**
     * The PHP sources under the directories that name the package, sorted by path.
     *
     * @param  list<string>  $directories
     * @return list<string>
     */
    private function sources(array $directories): array
    {
        // Finder refuses to iterate when it was given no directory at all.
        if ($directories === []) {
            return [];
        }

        $sources = [];

        foreach (Finder::create()->files()->name('*.php')->in($directories)->sortByName() as $file) {
            $source = $file->getContents();

            // A file that never names the package is never autoloaded or reflected.
            if (str_contains($source, 'AgenticActions\\')) {
                $sources[] = $source;
            }
        }

        return $sources;
    }

    /**
     * The nearest token before (-1) or after (1) a position that is not whitespace or a comment.
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function neighbour(array $tokens, int $index, int $step): ?PhpToken
    {
        for ($index += $step; isset($tokens[$index]); $index += $step) {
            if (! $tokens[$index]->isIgnorable()) {
                return $tokens[$index];
            }
        }

        return null;
    }

    /**
     * The first line of an error message.
     */
    private static function firstLine(string $message): string
    {
        return trim(explode("\n", $message, 2)[0]);
    }
}
