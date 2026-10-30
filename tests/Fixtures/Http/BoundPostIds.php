<?php

namespace Tests\Fixtures\Http;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Attributes\Rest;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * Return the two ids fixed by the REST path.
 */
#[Expose(web: true)]
#[Rest('GET', 'identity/{post_id}/{other_id}', bind: ['post_id' => 'post_id'])]
#[Rest('GET', 'swapped/{post_id}/{other_id}', bind: ['post_id' => 'other_id', 'other_id' => 'post_id'])]
final class BoundPostIds extends Action
{
    protected string $description = 'Return the path ids.';

    protected ?Effect $effect = Effect::Read;

    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['post_id' => $schema->integer()->required(), 'other_id' => $schema->integer()->required()];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return ['post_id' => $schema->integer()->required(), 'other_id' => $schema->integer()->required()];
    }

    public function handle(ValidatedInput $input): array
    {
        return $input->all();
    }
}
