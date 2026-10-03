<?php

use AgenticActions\Schema\SchemaReader;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\TranslatedNote;

it('reads agentSchema() only when the class overrides it', function () {
    $node = (new SchemaReader)->agentInput(new TranslatedNote);

    expect((new SchemaReader)->agentInput(new CreateNote))->toBeNull()
        ->and(array_keys($node['properties'] ?? []))->toBe(['headline'])
        ->and($node['required'] ?? [])->toBe(['headline']);
});
