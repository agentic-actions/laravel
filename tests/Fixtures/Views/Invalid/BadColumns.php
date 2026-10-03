<?php

namespace Tests\Fixtures\Views\Invalid;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use AgenticActions\Views\Column;
use AgenticActions\Views\ShowsTable;
use Closure;

/**
 * A table whose columns() the test chooses, to declare columns the package cannot show. Listed by its own tests only.
 */
#[Expose(agents: ['views'])]
final class BadColumns extends Action implements ShowsTable
{
    /**
     * The columns to declare.
     *
     * @var (Closure(): list<Column>)|null
     */
    public static ?Closure $columns = null;

    protected string $description = 'A table with the columns a test chooses.';

    protected ?Effect $effect = Effect::Read;

    protected bool $tenantScoped = false;

    /**
     * The columns the test chose, built now.
     */
    public function columns(): array
    {
        return self::$columns === null ? [Column::text('title', 'Title')] : (self::$columns)();
    }

    /**
     * Anyone.
     */
    public function authorize(ActionContext $context): bool
    {
        return true;
    }

    /**
     * No rows.
     *
     * @return list<array<string, mixed>>
     */
    public function handle(ActionContext $context): array
    {
        return [];
    }
}
