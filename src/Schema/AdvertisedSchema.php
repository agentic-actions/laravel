<?php

namespace AgenticActions\Schema;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\Type;

/**
 * What an agent is offered, and the prune that keeps a model's arguments inside it.
 *
 * @internal
 */
final class AdvertisedSchema
{
    /**
     * What an agent is offered: agentSchema() when overridden, else schema(), with the fields requiredForAgents() names
     * required and not nullable, minus the context's fixed keys.
     *
     * @upstream A named field's copy has its nullable flag cleared through a bound closure.
     *
     * @return array<string, Type>
     */
    public function types(Action $action, JsonSchema $schema, ActionContext $context): array
    {
        $types = $action->agentSchema($schema) ?? $action->schema($schema);

        // A copy of each type, so schema() stays what the route, the CLI and TypeScript read. The flag is protected,
        // and nullable() may only ever set it, so the closure runs bound to the copy and clears it.
        foreach (array_intersect_key($types, array_flip($action->requiredForAgents())) as $key => $type) {
            $types[$key] = (function (): Type {
                $this->nullable = null;

                return $this->required();
            })->call(clone $type);
        }

        return array_diff_key($types, $context->fixed);
    }

    /**
     * The same, serialized as the closed node a provider receives: additionalProperties false on every object, items
     * node and anyOf branch, as laravel/ai's ObjectSchema::toSchema() produces.
     *
     * @return array<string, mixed>
     */
    public function node(Action $action, ActionContext $context): array
    {
        $types = $this->types($action, new JsonSchemaTypeFactory, $context);

        return self::close((new ObjectType($types))->withoutAdditionalProperties()->toArray());
    }

    /**
     * Keep only the argument keys the node declares, at every depth (object properties and list items).
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public function prune(array $arguments, array $node): array
    {
        return self::pruneObject($arguments, $node);
    }

    /**
     * Set additionalProperties false on every object node, items node and anyOf branch, the same walk laravel/ai's
     * ObjectSchema::disableAdditionalProperties() does.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function close(array $schema): array
    {
        $type = $schema['type'] ?? null;

        if ($type === 'object' || (is_array($type) && in_array('object', $type))) {
            $schema['additionalProperties'] = false;

            foreach ($schema['properties'] ?? [] as $key => $property) {
                if (is_array($property)) {
                    $schema['properties'][$key] = self::close($property);
                }
            }
        }

        if (is_array($schema['items'] ?? null)) {
            $schema['items'] = self::close($schema['items']);
        }

        foreach ($schema['anyOf'] ?? [] as $key => $branch) {
            if (is_array($branch)) {
                $schema['anyOf'][$key] = self::close($branch);
            }
        }

        return $schema;
    }

    /**
     * Keep the declared properties of an object value, each pruned against its own node.
     *
     * @param  array<array-key, mixed>  $value
     * @param  array<string, mixed>  $node
     * @return array<array-key, mixed>
     */
    private static function pruneObject(array $value, array $node): array
    {
        $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];
        $kept = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && is_array($properties[$key] ?? null)) {
                $kept[$key] = self::pruneValue($item, $properties[$key]);
            }
        }

        return $kept;
    }

    /**
     * Prune one value against its node. A value whose shape does not match, and an anyOf value, are kept as they are,
     * so validation decides.
     *
     * @param  array<string, mixed>  $node
     */
    private static function pruneValue(mixed $value, array $node): mixed
    {
        if (isset($node['anyOf']) || ! is_array($value)) {
            return $value;
        }

        $types = (array) ($node['type'] ?? []);

        if (in_array('object', $types, true)) {
            return self::pruneObject($value, $node);
        }

        // A list offers positions, never names: keys a model put on it go, so no key it invented reaches validation,
        // or the sentence that names the rejected fields.
        if (in_array('array', $types, true) && is_array($node['items'] ?? null)) {
            return array_map(fn (mixed $item): mixed => self::pruneValue($item, $node['items']), array_values($value));
        }

        return $value;
    }
}
