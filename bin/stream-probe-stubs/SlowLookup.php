<?php

namespace App\Ai\Tools;

use AgenticActions\Contracts\DescribesActivity;
use AgenticActions\Streaming\Activity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * A hand-written tool with a row, as slow as SlowNote, which records its call the same way and reports its outcome.
 */
final class SlowLookup implements DescribesActivity, Tool
{
    /**
     * What the tool does, for the model.
     */
    public function description(): string
    {
        return 'Look the note up, slowly.';
    }

    /**
     * The probe run this call belongs to.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'run' => $schema->string()->required(),
        ];
    }

    /**
     * The row's labels.
     */
    public function activityLabel(bool $finished): ?string
    {
        return $finished ? 'Looked it up' : 'Looking it up…';
    }

    /**
     * Wait two seconds, record the call, and mark the row done.
     */
    public function handle(Request $request): string
    {
        $started = microtime(true);

        usleep(2_000_000);

        DB::table('probe_runs')->insert([
            'run' => (string) $request['run'],
            'tool' => 'SlowLookup',
            'started_at' => $started,
            'finished_at' => microtime(true),
            'connection_status' => connection_status(),
        ]);

        Activity::record($request, ok: true);

        return 'Found it.';
    }
}
