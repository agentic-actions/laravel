<?php

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Schema\AdvertisedSchema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\StringType;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\ObjectSchema;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Misconfigured\WebWithAgentSchema;

/**
 * Whether the framework's JsonSchema builds anyOf types (Laravel 13; Laravel 12 has none).
 */
function schemaHasAnyOf(): bool
{
    return method_exists(JsonSchemaTypeFactory::class, 'anyOf');
}

/**
 * An action with a nested object, a list of objects and, where the framework builds one, an anyOf key.
 */
function nestedAction(): Action
{
    return new class extends Action
    {
        public function schema(JsonSchema $schema): array
        {
            $types = [
                'title' => $schema->string()->required(),
                'meta' => $schema->object([
                    'author' => $schema->string()->required(),
                    'extra' => $schema->object(['level' => $schema->integer()]),
                ]),
                'items' => $schema->array()->items($schema->object(['name' => $schema->string()->required()])),
            ];

            if (schemaHasAnyOf()) {
                $types['either'] = $schema->anyOf([
                    $schema->object(['a' => $schema->string()]),
                    $schema->string(),
                ]);
            }

            return $types;
        }
    };
}

it('offers agentSchema() when the class overrides it', function () {
    $types = (new AdvertisedSchema)->types(new WebWithAgentSchema, new JsonSchemaTypeFactory, ActionContext::agent(null));

    expect(array_keys($types))->toBe(['current_title', 'title']);
});

it('offers schema() otherwise, minus the context\'s fixed keys', function () {
    $context = ActionContext::agent(null)->withFixed(['body' => 'Set by the host.']);

    expect(array_keys((new AdvertisedSchema)->types(new CreateNote, new JsonSchemaTypeFactory, $context)))->toBe(['title', 'excerpt'])
        ->and(array_keys((new AdvertisedSchema)->node(new CreateNote, $context)['properties']))->toBe(['title', 'excerpt']);
});

it('offers a field requiredForAgents() names as required and not nullable, whatever its type\'s nullable() does', function () {
    $action = new class extends Action
    {
        public function schema(JsonSchema $schema): array
        {
            // A type whose nullable() only ever sets the flag.
            $settingOnly = new class extends StringType
            {
                public function nullable(bool $nullable = true): static
                {
                    if ($nullable) {
                        $this->nullable = true;
                    }

                    return $this;
                }
            };

            return ['publish_on' => $settingOnly->nullable(), 'title' => $schema->string()->nullable()];
        }

        public function requiredForAgents(): array
        {
            return ['publish_on'];
        }
    };

    // The serializer takes the framework's own classes only, so the flags are read from the copy.
    $flags = fn (Type $type): array => (fn (): array => ['required' => $this->required, 'nullable' => $this->nullable])->call($type);
    $types = (new AdvertisedSchema)->types($action, new JsonSchemaTypeFactory, ActionContext::agent(null));

    expect($flags($types['publish_on']))->toBe(['required' => true, 'nullable' => null])
        ->and($flags($types['title']))->toBe(['required' => null, 'nullable' => true]);
});

it('closes every object, list items node and anyOf branch', function () {
    $node = (new AdvertisedSchema)->node(nestedAction(), ActionContext::agent(null));

    expect($node['additionalProperties'])->toBeFalse()
        ->and($node['properties']['meta']['additionalProperties'])->toBeFalse()
        ->and($node['properties']['meta']['properties']['extra']['additionalProperties'])->toBeFalse()
        ->and($node['properties']['items']['items']['additionalProperties'])->toBeFalse();

    if (schemaHasAnyOf()) {
        expect($node['properties']['either']['anyOf'][0]['additionalProperties'])->toBeFalse()
            ->and($node['properties']['either']['anyOf'][1])->not->toHaveKey('additionalProperties');
    }
});

it('produces what laravel/ai sends a provider', function () {
    $this->skipUnlessAi();

    $action = nestedAction();
    $types = (new AdvertisedSchema)->types($action, new JsonSchemaTypeFactory, ActionContext::agent(null));

    expect((new AdvertisedSchema)->node($action, ActionContext::agent(null)))
        ->toBe((new ObjectSchema($types))->toSchema());
});

it('prunes undeclared keys at every depth, keeping order', function () {
    $schema = new AdvertisedSchema;
    $node = $schema->node(nestedAction(), ActionContext::agent(null));

    expect($schema->prune([
        'team_id' => 9,
        'title' => 'Hi',
        'meta' => ['author' => 'Sam', 'role' => 'admin', 'extra' => ['level' => 2, 'secret' => 'x']],
        'items' => [['name' => 'A', 'user_id' => 1], ['name' => 'B'], 'not an object'],
        'either' => ['a' => 'kept', 'b' => 'kept too'],
    ], $node))->toBe([
        'title' => 'Hi',
        'meta' => ['author' => 'Sam', 'extra' => ['level' => 2]],
        'items' => [['name' => 'A'], ['name' => 'B'], 'not an object'],
        ...(schemaHasAnyOf() ? ['either' => ['a' => 'kept', 'b' => 'kept too']] : []),
    ]);
});

it('keeps a value whose shape does not match its node, so validation decides', function () {
    $schema = new AdvertisedSchema;
    $node = $schema->node(nestedAction(), ActionContext::agent(null));

    expect($schema->prune(['meta' => 'a string', 'items' => 'not a list', 'title' => ['an' => 'array']], $node))
        ->toBe(['meta' => 'a string', 'items' => 'not a list', 'title' => ['an' => 'array']]);
});

it('keeps nothing for an object node without properties', function () {
    $schema = new AdvertisedSchema;

    expect($schema->prune(['anything' => 1], ['type' => 'object', 'additionalProperties' => false]))->toBe([])
        ->and($schema->prune(['meta' => ['a' => 1]], ['type' => 'object', 'properties' => ['meta' => ['type' => 'object']]]))
        ->toBe(['meta' => []]);
});

it('lists null among a nullable choice\'s values at every depth, so a model may leave it empty', function () {
    $action = new class extends Action
    {
        public function schema(JsonSchema $schema): array
        {
            return [
                'status' => $schema->string()->enum(['draft', 'published'])->nullable(),
                'kind' => $schema->string()->enum(['a', 'b'])->required(),
                'already' => $schema->string()->enum(['x', null])->nullable(),
                'query' => $schema->object([
                    'grain' => $schema->string()->enum(['day', 'week'])->nullable(),
                    'by' => $schema->array()->items($schema->string()->enum(['course', 'group'])->nullable())->nullable(),
                ]),
            ];
        }
    };

    $node = (new AdvertisedSchema)->node($action, ActionContext::agent(null));

    expect($node['properties']['status']['enum'])->toBe(['draft', 'published', null])
        ->and($node['properties']['kind']['enum'])->toBe(['a', 'b'])
        ->and($node['properties']['already']['enum'])->toBe(['x', null])
        ->and($node['properties']['query']['properties']['grain']['enum'])->toBe(['day', 'week', null])
        ->and($node['properties']['query']['properties']['by']['items']['enum'])->toBe(['course', 'group', null]);

    // schema() itself stays as the action wrote it: the route, the CLI and TypeScript read it.
    $written = $action->schema(new JsonSchemaTypeFactory);

    expect($written['status']->toArray()['enum'])->toBe(['draft', 'published'])
        ->and($written['query']->toArray()['properties']['grain']['enum'])->toBe(['day', 'week']);
});

it('does not list null for a field requiredForAgents() names, though schema() makes it nullable', function () {
    $action = new class extends Action
    {
        public function schema(JsonSchema $schema): array
        {
            return ['level' => $schema->string()->enum(['low', 'high'])->nullable()];
        }

        public function requiredForAgents(): array
        {
            return ['level'];
        }
    };

    expect((new AdvertisedSchema)->node($action, ActionContext::agent(null))['properties']['level']['enum'])->toBe(['low', 'high']);
});
