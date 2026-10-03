<?php

namespace Tests\Fixtures\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * Records every hook it runs in Trace, and lets a test choose what authorize() and handle() do, so a test can see
 * where the pipeline stops and that later steps never run.
 */
final class TracedNote extends Action
{
    /**
     * How many instances the container has built.
     */
    public static int $instances = 0;

    /**
     * What shouldRegister() answers.
     */
    public static bool $registers = true;

    /**
     * What authorize() returns, or a closure it calls.
     */
    public static bool|Response|Closure|null $authorizes = true;

    /**
     * What handle() does, or null to return the title.
     */
    public static ?Closure $handles = null;

    protected ?Effect $effect = Effect::Write;

    /**
     * Count the instance. It never changes a property.
     */
    public function __construct()
    {
        self::$instances++;
    }

    /**
     * Put every static back to its default.
     */
    public static function reset(): void
    {
        self::$instances = 0;
        self::$registers = true;
        self::$authorizes = true;
        self::$handles = null;
    }

    /**
     * The note's title.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(20)->required(),
        ];
    }

    /**
     * The title back.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
        ];
    }

    /**
     * Record the step.
     */
    public function rules(ActionContext $context): array
    {
        Trace::record('rules');

        return [];
    }

    /**
     * Record the step and answer as the test chose.
     */
    public function shouldRegister(ActionContext $context): bool
    {
        Trace::record('shouldRegister');

        return self::$registers;
    }

    /**
     * Record the step and answer as the test chose.
     */
    public function authorize(ActionContext $context): bool|Response|null
    {
        Trace::record('authorize');

        return self::$authorizes instanceof Closure ? (self::$authorizes)() : self::$authorizes;
    }

    /**
     * Record the step.
     */
    public function prepareForValidation(array $input, ActionContext $context): array
    {
        Trace::record('prepareForValidation');

        return $input;
    }

    /**
     * Record the step and do what the test chose.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        Trace::record('handle');

        return self::$handles instanceof Closure ? (self::$handles)($input, $context) : ['title' => $input->string('title')->toString()];
    }

    /**
     * Record the step.
     */
    public function modelReply(mixed $result, ActionContext $context): ?string
    {
        Trace::record('modelReply');

        return null;
    }
}
