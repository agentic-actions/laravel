<?php

namespace Tests\Fixtures\Views\Invalid;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use AgenticActions\Views\Column;
use AgenticActions\Views\ShowsTable;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * A Write that implements ShowsTable: an ordinary action, whose output is its outputSchema(), and one actions:check
 * fails. Listed by its own tests only.
 */
final class WriteWithTable extends Action implements ShowsTable
{
    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    /**
     * Whether it saved.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['saved' => $schema->boolean()->required()];
    }

    /**
     * A title, which no surface ever shows.
     */
    public function columns(): array
    {
        return [Column::text('title', 'Title')];
    }

    /**
     * Anyone.
     */
    public function authorize(ActionContext $context): bool
    {
        return true;
    }

    /**
     * A row's worth of keys, of which only its output's leave.
     *
     * @return array{saved: bool, title: string}
     */
    public function handle(ActionContext $context): array
    {
        return ['saved' => true, 'title' => 'CANARY-TITLE'];
    }
}
