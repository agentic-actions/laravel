<?php

namespace AgenticActions\Streaming;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Which laravel/ai conversation one participant continues with one agent class in one tenant, or with no tenant. The
 * table comes from the migration tagged agentic-actions-migrations, and lives on laravel/ai's conversation connection.
 * Actions::conversation() reads and writes it; an app reads it only to list or delete a person's rows.
 *
 * @property int $id
 * @property string $participant_type
 * @property int|string $participant_id
 * @property string|null $tenant_type
 * @property int|string|null $tenant_id
 * @property string $agent
 * @property string $conversation_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api
 */
final class AgenticConversation extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['participant_type', 'participant_id', 'tenant_type', 'tenant_id', 'agent', 'conversation_id'];

    /**
     * laravel/ai's conversation connection, as its own models read it.
     */
    public function getConnectionName(): ?string
    {
        $connection = config('ai.conversations.connection');

        return is_string($connection) ? $connection : null;
    }

    /**
     * The person having the conversation.
     *
     * @return MorphTo<Model, $this>
     */
    public function participant(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The tenant the conversation belongs to, or none.
     *
     * @return MorphTo<Model, $this>
     */
    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }
}
