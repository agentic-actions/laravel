<?php

namespace AgenticActions\Schema;

use AgenticActions\Action;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Views\ShowsTable;
use AgenticActions\Views\Table;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\Type;

/**
 * Reads an action's schemas as serialized arrays: the declared shape, which HTTP, the CLI and TypeScript use.
 *
 * @internal
 */
final class SchemaReader
{
    /**
     * schema() as a serialized object node: ['type' => 'object', 'properties' => [...], 'required' => [...]].
     *
     * @return array<string, mixed>
     */
    public function input(Action $action): array
    {
        return self::serialize($action->schema(new JsonSchemaTypeFactory));
    }

    /**
     * agentSchema() as a serialized object node, or null when the class does not override it.
     *
     * @return array<string, mixed>|null
     */
    public function agentInput(Action $action): ?array
    {
        $types = $action->agentSchema(new JsonSchemaTypeFactory);

        return $types === null ? null : self::serialize($types);
    }

    /**
     * outputSchema() as a serialized object node; for an action that shows a table, the table's output types.
     *
     * @return array<string, mixed>
     */
    public function output(Action $action): array
    {
        $schema = new JsonSchemaTypeFactory;

        return self::serialize($action instanceof ShowsTable && ClassExposure::of($action::class)->shows()
            ? Table::schema($schema, Table::columns($action))
            : $action->outputSchema($schema));
    }

    /**
     * Wrap the properties in an object and serialize it.
     *
     * @param  array<string, Type>  $types
     * @return array<string, mixed>
     */
    private static function serialize(array $types): array
    {
        return (new ObjectType($types))->toArray();
    }
}
