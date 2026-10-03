<?php

namespace AgenticActions\Exposure;

use AgenticActions\Action;
use AgenticActions\Attributes\Expose;
use AgenticActions\Datasets\Dataset;
use AgenticActions\Effect;
use AgenticActions\Support\Packages;
use AgenticActions\Surface;
use AgenticActions\Views\ShowsTable;
use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;

/**
 * Static facts about one action: what the manifest stores and what ClassExposure re-reads.
 *
 * @internal
 */
final class Entry
{
    /**
     * Create an entry.
     *
     * @param  class-string<Action>  $class
     * @param  list<string>  $touches
     * @param  list<string>  $toolsets  empty unless the agent surface is open
     * @param  list<Surface>  $surfaces  opened surfaces, always starting with Console
     * @param  array<string, string>  $skipped  surface value => reason
     * @param  list<string>  $errors  "surface: reason" for surfaces named on purpose that the rules refuse
     * @param  bool  $asks  whether a model's incomplete call asks the person in a form ($askForMissing on a Read or Write action)
     */
    public function __construct(
        public readonly string $class,
        public readonly string $name,
        public readonly string $description,
        public readonly ?Effect $effect,
        public readonly bool $idempotent,
        public readonly bool $tenantScoped,
        public readonly array $touches,
        public readonly bool $guests,
        public readonly bool $validationMessagesToModel,
        public readonly string $errorBag,
        public readonly array $toolsets,
        public readonly bool $hasAgentSchema,
        public readonly array $surfaces,
        public readonly array $skipped,
        public readonly array $errors,
        public readonly bool $followLink = false,
        public readonly bool $asks = false,
    ) {}

    /**
     * Read one class by reflection: default property values and own attributes only; no constructor runs.
     *
     * @param  class-string<Action>  $class
     *
     * @throws InvalidArgumentException when the class is not a concrete action
     */
    public static function fromClass(string $class): self
    {
        if (! is_subclass_of($class, Action::class)) {
            throw new InvalidArgumentException("[{$class}] is not an ".Action::class.'.');
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract()) {
            throw new InvalidArgumentException("[{$class}] is abstract.");
        }

        $defaults = $reflection->getDefaultProperties();
        $expose = ($reflection->getAttributes(Expose::class)[0] ?? null)?->newInstance();

        $name = is_string($defaults['name'] ?? null) && $defaults['name'] !== ''
            ? $defaults['name']
            : Str::kebab(Str::replaceEnd('Action', '', $reflection->getShortName()) ?: $reflection->getShortName());

        $effect = $defaults['effect'] ?? null;
        $effect = $effect instanceof Effect ? $effect : null;
        $description = (string) ($defaults['description'] ?? '');

        $hasAgentSchema = $reflection->getMethod('agentSchema')->getDeclaringClass()->getName() !== Action::class;
        $dataset = $reflection->isSubclassOf(Dataset::class);

        [$surfaces, $skipped, $errors] = ExposureRule::decide(
            $expose, $effect, $description, $hasAgentSchema, app(Packages::class)->laravelAi(),
        );

        // A dataset's own error closes every remote surface, as a refused surface stays closed.
        $datasetErrors = $dataset ? self::datasetErrors($reflection, $effect) : [];

        if ($datasetErrors !== []) {
            $surfaces = [Surface::Console];
        }

        $toolsets = in_array(Surface::Agent, $surfaces, true) && $expose !== null
            ? ($expose->isBare() ? ['default'] : array_values(array_unique($expose->agents ?? [])))
            : [];

        return new self(
            class: $class,
            name: $name,
            description: $description,
            effect: $effect,
            idempotent: (bool) ($defaults['idempotent'] ?? false),
            tenantScoped: (bool) ($defaults['tenantScoped'] ?? true) && config('agentic-actions.tenant.model') !== null,
            touches: array_values(array_map(strval(...), (array) ($defaults['touches'] ?? []))),
            guests: (bool) ($defaults['guests'] ?? false),
            validationMessagesToModel: (bool) ($defaults['validationMessagesToModel'] ?? false),
            errorBag: self::errorBag($reflection, $defaults),
            toolsets: $toolsets,
            hasAgentSchema: $hasAgentSchema,
            surfaces: $surfaces,
            skipped: $skipped,
            errors: [...$errors, ...$datasetErrors],
            followLink: (bool) ($defaults['followLink'] ?? false),
            // Only a Read or Write action (or one with no effect) asks: a Destructive or External one never does, and
            // neither does a dataset, whose call is generated.
            asks: ! $dataset && (bool) ($defaults['askForMissing'] ?? false) && $effect?->isModelSafe() !== false,
        );
    }

    /**
     * A placeholder for a name nothing declares: no class facts and no open surface, used only to build a not-found
     * outcome.
     */
    public static function unknown(string $name): self
    {
        return new self(
            class: Action::class,
            name: $name,
            description: '',
            effect: null,
            idempotent: false,
            tenantScoped: false,
            touches: [],
            guests: false,
            validationMessagesToModel: false,
            errorBag: 'default',
            toolsets: [],
            hasAgentSchema: false,
            surfaces: [],
            skipped: [],
            errors: [],
            followLink: false,
            asks: false,
        );
    }

    /**
     * Rebuild from a manifest row.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromManifest(array $row): self
    {
        /** @var class-string<Action> $class */
        $class = (string) $row['class'];
        $effect = is_string($row['effect'] ?? null) ? Effect::tryFrom($row['effect']) : null;

        return new self(
            class: $class,
            name: (string) $row['name'],
            description: (string) ($row['description'] ?? ''),
            effect: $effect,
            idempotent: (bool) ($row['idempotent'] ?? false),
            tenantScoped: (bool) ($row['tenant_scoped'] ?? false),
            touches: self::strings($row['touches'] ?? []),
            guests: (bool) ($row['guests'] ?? false),
            validationMessagesToModel: (bool) ($row['validation_messages_to_model'] ?? false),
            errorBag: (string) ($row['error_bag'] ?? 'default'),
            toolsets: self::strings($row['toolsets'] ?? []),
            hasAgentSchema: (bool) ($row['has_agent_schema'] ?? false),
            surfaces: array_values(array_filter(array_map(
                fn (mixed $surface): ?Surface => is_string($surface) ? Surface::tryFrom($surface) : null,
                (array) ($row['surfaces'] ?? []),
            ))),
            skipped: array_map(strval(...), (array) ($row['skipped'] ?? [])),
            errors: self::strings($row['errors'] ?? []),
            followLink: (bool) ($row['follow_link'] ?? false),
            // As fromClass() reads it: whatever a row says, a Destructive or External action never asks.
            asks: (bool) ($row['ask_for_missing'] ?? false) && $effect?->isModelSafe() !== false,
        );
    }

    /**
     * The manifest row.
     *
     * @return array<string, mixed>
     */
    public function toManifest(): array
    {
        return [
            'class' => $this->class,
            'name' => $this->name,
            'description' => $this->description,
            'effect' => $this->effect?->value,
            'idempotent' => $this->idempotent,
            'tenant_scoped' => $this->tenantScoped,
            'touches' => $this->touches,
            'guests' => $this->guests,
            'validation_messages_to_model' => $this->validationMessagesToModel,
            'error_bag' => $this->errorBag,
            'toolsets' => $this->toolsets,
            'has_agent_schema' => $this->hasAgentSchema,
            'surfaces' => array_map(fn (Surface $surface): string => $surface->value, $this->surfaces),
            'skipped' => $this->skipped,
            'errors' => $this->errors,
            'follow_link' => $this->followLink,
            'ask_for_missing' => $this->asks,
        ];
    }

    /**
     * The snapshot row.
     *
     * @return array{class: string, effect: string|null, web: bool, agents: list<string>, mcp: bool, tenant_scoped: bool}
     */
    public function toSnapshot(): array
    {
        return [
            'class' => $this->class,
            'effect' => $this->effect?->value,
            'web' => $this->allows(Surface::Http),
            'agents' => $this->toolsets,
            'mcp' => $this->allows(Surface::Mcp),
            'tenant_scoped' => $this->tenantScoped,
        ];
    }

    /**
     * Whether a surface is open.
     */
    public function allows(Surface $surface): bool
    {
        return in_array($surface, $this->surfaces, true);
    }

    /**
     * Whether the action shows its rows as a table: a Read that implements ShowsTable. Computed from the class, so the
     * manifest stores nothing new; every consumer asks here.
     */
    public function shows(): bool
    {
        return $this->effect === Effect::Read && is_subclass_of($this->class, ShowsTable::class);
    }

    /**
     * The generated route segment: Str::kebab($this->name).
     */
    public function segment(): string
    {
        return Str::kebab($this->name);
    }

    /**
     * The action instance, resolved through the container.
     */
    public function action(): Action
    {
        $action = app($this->class);

        if (! $action instanceof Action) {
            throw new InvalidArgumentException("[{$this->class}] did not resolve to an ".Action::class.'.');
        }

        return $action;
    }

    /**
     * A dataset's own errors: its call and output are generated, so it declares neither agentSchema() nor
     * outputSchema(), and it only reads.
     *
     * @param  ReflectionClass<Action>  $reflection
     * @return list<string>
     */
    private static function datasetErrors(ReflectionClass $reflection, ?Effect $effect): array
    {
        $errors = $effect === Effect::Read ? [] : ['a dataset is a Read action: remove its $effect'];

        foreach (['agentSchema', 'outputSchema'] as $method) {
            if ($reflection->getMethod($method)->getDeclaringClass()->getName() !== Action::class) {
                $errors[] = "{$method}(): a dataset's schemas are generated";
            }
        }

        return $errors;
    }

    /**
     * The error bag: a Laravel 13 #[ErrorBag] on the class wins over the declared $errorBag.
     *
     * @param  ReflectionClass<Action>  $reflection
     * @param  array<string, mixed>  $defaults
     */
    private static function errorBag(ReflectionClass $reflection, array $defaults): string
    {
        if (class_exists(ErrorBag::class) && ($attribute = $reflection->getAttributes(ErrorBag::class)[0] ?? null) !== null) {
            return $attribute->newInstance()->name;
        }

        return (string) ($defaults['errorBag'] ?? 'default');
    }

    /**
     * A list of strings from a manifest value.
     *
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return array_values(array_map(strval(...), (array) $values));
    }
}
