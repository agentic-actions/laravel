<?php

namespace AgenticActions\Console;

use AgenticActions\ActionContext;
use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Outcome;
use AgenticActions\Pipeline\Door;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Runner;
use AgenticActions\Tenancy\Tenants;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use JsonException;
use stdClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs an action as an operator at the CLI: the in-process door, the Console surface, the full pipeline. The output
 * prints as JSON, or as a table for an action that shows one.
 *
 * @internal
 */
#[AsCommand(name: 'actions:run')]
final class RunCommand extends Command
{
    /**
     * The exit code for a denied call. Invalid input exits with Command::INVALID (2).
     */
    private const DENIED = 3;

    /**
     * The exit code for a crash.
     */
    private const CRASHED = 4;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'actions:run
        {name : The action\'s name}
        {input?* : key=value pairs; dotted keys nest (tags.0=news)}
        {--as= : The actor\'s identifier, looked up through the default guard\'s user provider}
        {--tenant= : The tenant\'s route key}
        {--locale= : The locale; defaults to app.locale}
        {--key= : A raw idempotency key}
        {--input= : A JSON object; key=value pairs are applied on top of it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run an action through the full pipeline, as an operator';

    /**
     * Execute the console command.
     */
    public function handle(ActionRegistry $registry, Runner $runner): int
    {
        $name = (string) $this->argument('name');

        if (($entry = $registry->find($name)) === null) {
            return $this->stop("No action named [{$name}]. Run php artisan actions:list.");
        }

        $input = $this->payload();

        if (is_string($input)) {
            return $this->stop($input);
        }

        $actor = $this->actor();

        if (is_string($actor)) {
            return $this->stop($actor);
        }

        $tenant = $this->tenant();

        if (is_string($tenant)) {
            return $this->stop($tenant);
        }

        $locale = $this->stringOption('locale');

        try {
            $context = ActionContext::console(
                $actor,
                $tenant,
                $locale !== null && $locale !== '' ? $locale : (string) config('app.locale'),
                $this->stringOption('key'),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->stop($exception->getMessage());
        }

        return $this->render($runner->run($entry, $input, $context, Door::InProcess));
    }

    /**
     * The input: the --input object, with each key=value pair set on top as a string. A string is an error.
     *
     * @return array<string, mixed>|string
     */
    private function payload(): array|string
    {
        $input = [];
        $json = $this->option('input');

        if (is_string($json)) {
            try {
                $object = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $object = null;
            }

            if (! $object instanceof stdClass) {
                return 'The --input option must be a JSON object.';
            }

            $input = (array) json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        }

        foreach ((array) $this->argument('input') as $pair) {
            $pair = (string) $pair;

            if (! str_contains($pair, '=') || str_starts_with($pair, '=')) {
                return "The input [{$pair}] is not a key=value pair.";
            }

            [$key, $value] = explode('=', $pair, 2);

            // Values stay strings: the Console surface's form-style coercion converts them against the schema.
            Arr::set($input, $key, $value);
        }

        /** @var array<string, mixed> $input */
        return $input;
    }

    /**
     * The actor --as names, through the default guard's user provider, or null without --as. A string is an error.
     */
    private function actor(): Authenticatable|string|null
    {
        $identifier = $this->stringOption('as');

        if ($identifier === null) {
            return null;
        }

        $guard = config('auth.defaults.guard');
        $provider = Auth::createUserProvider(is_string($guard) ? config("auth.guards.{$guard}.provider") : null);
        $actor = $provider?->retrieveById($identifier);

        return $actor instanceof Authenticatable ? $actor : "No user [{$identifier}].";
    }

    /**
     * The tenant --tenant names by route key, or null without --tenant. A string is an error.
     */
    private function tenant(): Model|string|null
    {
        $key = $this->stringOption('tenant');

        if ($key === null) {
            return null;
        }

        if (config('agentic-actions.tenant.model') === null) {
            return 'The --tenant option needs a tenant model: set tenant.model in config/agentic-actions.php.';
        }

        return Tenants::resolve($key) ?? "No tenant [{$key}].";
    }

    /**
     * Print an outcome and choose the exit code.
     */
    private function render(Outcome $outcome): int
    {
        $locale = $outcome->context()->locale;

        switch ($outcome->kind()) {
            case OutcomeKind::Ok:
                $output = $outcome->output() ?? [];

                if ($outcome->entry()->shows()) {
                    $this->printTable($output, $locale);

                    return self::SUCCESS;
                }

                $this->output->writeln(
                    $output === [] ? '{}' : json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    OutputInterface::OUTPUT_RAW,
                );

                return self::SUCCESS;

            case OutcomeKind::Invalid:
                foreach ($outcome->errors() as $key => $messages) {
                    $this->stderr("{$key}: ".implode(' ', $messages));
                }

                return self::INVALID;

            case OutcomeKind::Failed:
                $exception = $outcome->exception();

                // The runner has already reported the throwable.
                $this->stderr($exception === null ? 'The action failed.' : $exception::class.': '.$exception->getMessage());

                return self::CRASHED;

            default:
                $this->stderr((string) $outcome->refusal()?->translate($locale));

                if ($this->stringOption('as') === null && in_array($outcome->kind(), [OutcomeKind::NotFound, OutcomeKind::Denied], true)) {
                    $this->stderr('No --as was given, so the action ran without a user.');
                }

                return $outcome->kind() === OutcomeKind::Denied ? self::DENIED : self::FAILURE;
        }
    }

    /**
     * A table's output as a table: its caption first when it has one, its labels as the headers, each value raw, and a
     * last line when it was cut.
     *
     * @param  array<string, mixed>  $output
     */
    private function printTable(array $output, string $locale): void
    {
        $columns = array_values(array_filter((array) ($output['columns'] ?? []), is_array(...)));
        $rows = array_values((array) ($output['rows'] ?? []));

        // A dataset's caption says what was asked: its dates, filters and sort.
        if (is_string($output['caption'] ?? null) && $output['caption'] !== '') {
            $this->line(self::plain($output['caption']));
        }

        $this->table(
            array_map(fn (array $column): string => self::plain((string) ($column['label'] ?? '')), $columns),
            array_map(fn (mixed $row): array => array_map(fn (array $column): string => self::plain(self::cell(((array) $row)[$column['key'] ?? ''] ?? null)), $columns), $rows),
        );

        if (($output['truncated'] ?? false) === true) {
            $this->line((string) trans('agentic-actions::views.truncated', ['count' => count($rows)], $locale));
        }
    }

    /**
     * Text as the terminal shows it, never as it would act: control characters removed, so no escape sequence reaches
     * the terminal, and the console's own tags escaped, so a cell reading <href=…> stays text.
     */
    private static function plain(string $text): string
    {
        return OutputFormatter::escape(preg_replace('/[\x00-\x1F\x7F\x{80}-\x{9F}]/u', '', $text) ?? '');
    }

    /**
     * A cell as actions:run prints it: yes or no as true or false, any other scalar as it is, null as nothing.
     */
    private static function cell(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? (string) $value : '');
    }

    /**
     * An option's value as a string, or null when it is absent. An integer counts, since a test passes one to
     * $this->artisan() as ['--as' => 1]; the shell always passes a string.
     */
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_int($value) || is_string($value) ? (string) $value : null;
    }

    /**
     * Print an error that stops the command before the action runs.
     */
    private function stop(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }

    /**
     * Write one raw line to stderr.
     */
    private function stderr(string $line): void
    {
        $this->output->getErrorStyle()->writeln($line, OutputInterface::OUTPUT_RAW);
    }
}
