<?php

namespace AgenticActions\Streaming;

use AgenticActions\Outcome;
use AgenticActions\Views\Rows;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * A table a person was shown in a stored conversation, kept as it was shown, so a reload shows the table the model's
 * words describe; its id is the ref a refresh names. The table comes from the migration tagged
 * agentic-actions-views-migrations and lives on laravel/ai's conversation connection. Stored as the app stores other
 * data, unencrypted, like the conversation, and kept as long as it: schedule model:prune for this class. An app reads
 * it only to list or delete a person's rows.
 *
 * @property string $id
 * @property string $conversation_id
 * @property string $tool_call_id
 * @property string $participant_type
 * @property int|string $participant_id
 * @property string|null $tenant_type
 * @property int|string|null $tenant_id
 * @property string $action
 * @property array<string, mixed> $input
 * @property array<string, mixed> $fixed
 * @property array<string, mixed> $table
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api
 */
final class AgenticView extends Model
{
    use HasUlids;
    use MassPrunable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['conversation_id', 'tool_call_id', 'participant_type', 'participant_id', 'tenant_type', 'tenant_id', 'action', 'input', 'fixed', 'table'];

    /**
     * What the call ran with and what it showed, never serialized.
     *
     * @var list<string>
     */
    protected $hidden = ['input', 'fixed', 'table'];

    /**
     * The attributes' casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['input' => 'array', 'fixed' => 'array', 'table' => 'array'];
    }

    /**
     * laravel/ai's conversation connection, as its own models read it.
     */
    public function getConnectionName(): ?string
    {
        $connection = config('ai.conversations.connection');

        return is_string($connection) ? $connection : null;
    }

    /**
     * Keep the table a successful call showed an actor in a conversation, with its tool-call id, the input as it entered
     * prepareForValidation() (with a result's own input over it, such as a dataset's resolved dates) and the fixed
     * keys, so a refresh can run it again. A second table under one tool-call id of a conversation keeps neither. Never
     * throws: null when nothing was kept, a failure reported, a missing table at most once an hour.
     *
     * @internal
     */
    public static function record(Outcome $outcome, string $conversationId, string $toolCallId): ?self
    {
        $key = ['conversation_id' => $conversationId, 'tool_call_id' => $toolCallId];

        try {
            $context = $outcome->context();
            $result = $outcome->result();

            if (! $outcome->ok() || ! $context->actor instanceof Model) {
                return null;
            }

            try {
                return (new self)->getConnection()->transaction(fn (): self => self::query()->create([
                    ...$key,
                    'participant_type' => $context->actor->getMorphClass(),
                    'participant_id' => $context->actor->getKey(),
                    'tenant_type' => $context->tenant?->getMorphClass(),
                    'tenant_id' => $context->tenant?->getKey(),
                    'action' => $outcome->entry()->name,
                    'input' => [...$outcome->input(), ...($result instanceof Rows ? $result->input : [])],
                    'fixed' => $outcome->fixed(),
                    'table' => $outcome->output() ?? [],
                ]));
            } catch (UniqueConstraintViolationException) {
                self::query()->where($key)->delete();
            }
        } catch (Throwable $exception) {
            self::reportFailure($exception);
        }

        return null;
    }

    /**
     * Report a failure to keep or read the tables shown; while their table is missing, at most once an hour.
     *
     * @internal
     */
    public static function reportFailure(Throwable $exception): void
    {
        try {
            $view = new self;
            $present = rescue(fn (): bool => $view->getConnection()->getSchemaBuilder()->hasTable($view->getTable()), true, false);

            if ($present || Cache::add('agentic-actions:views-missing', true, 3600)) {
                report($exception);
            }
        } catch (Throwable) {
            // A reporter or a cache that throws must not reach the tool loop or the page.
        }
    }

    /**
     * The rows whose laravel/ai conversation no longer exists, for model:prune. A row a day old at least: a new
     * conversation's row is stored when its first turn ends, so a table that turn showed waits for it.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $conversations = (string) config('ai.conversations.tables.conversations', 'agent_conversations');

        return self::query()->where('created_at', '<', now()->subDay())->whereNotExists(fn (QueryBuilder $query): QueryBuilder => $query->selectRaw('1')->from($conversations)
            ->whereColumn("{$conversations}.id", $this->qualifyColumn('conversation_id')));
    }
}
