<?php

namespace App\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ValidatedInput;

/**
 * A Write that takes two seconds, then records when it ran and what connection_status() said.
 */
#[Expose(agents: ['probe'])]
final class SlowNote extends Action
{
    protected string $description = 'Save a note, slowly.';

    protected ?Effect $effect = Effect::Write;

    protected array $touches = ['notes'];

    /**
     * The probe run this call belongs to.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'run' => $schema->string()->max(40)->required(),
        ];
    }

    /**
     * Any signed-in user.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor instanceof User;
    }

    /**
     * The row's labels.
     */
    public function activityLabel(ActionContext $context, bool $finished): ?string
    {
        return $finished ? 'Note saved' : 'Saving the note…';
    }

    /**
     * Wait two seconds, then record the call.
     */
    public function handle(ActionContext $context, ValidatedInput $input): void
    {
        $started = microtime(true);

        usleep(2_000_000);

        DB::table('probe_runs')->insert([
            'run' => $input->string('run')->toString(),
            'tool' => 'slow-note',
            'started_at' => $started,
            'finished_at' => microtime(true),
            'connection_status' => connection_status(),
        ]);
    }
}
