<?php

namespace AgenticActions\Streaming;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;
use LogicException;

/**
 * The one laravel/ai conversation a participant continues with an agent class in a tenant, from
 * Actions::conversation(). Its key is the participant, the tenant and the agent class the server passed, never
 * anything a request carries, so no person or tenant reaches another's conversation through it.
 *
 * @api
 */
final class ConversationSlot
{
    /**
     * Create the slot for a saved participant and a saved tenant, or no tenant.
     *
     * @param  class-string  $agent
     *
     * @throws LogicException when the participant or the tenant has no key yet
     */
    public function __construct(
        private readonly string $agent,
        private readonly Model $participant,
        private readonly ?Model $tenant = null,
    ) {
        if ($participant->getKey() === null || ($tenant !== null && $tenant->getKey() === null)) {
            throw new LogicException('A conversation belongs to a saved participant and a saved tenant.');
        }
    }

    /**
     * The conversation's id, or null until open() first ran for this participant, tenant and agent.
     */
    public function id(): ?string
    {
        // Without a tenant, two first turns at the same instant can each open one; the older wins from then on.
        $id = AgenticConversation::query()->where($this->key())->orderBy('id')->value('conversation_id');

        return is_string($id) ? $id : null;
    }

    /**
     * The conversation's id, stored in laravel/ai's conversation store the first time with the given title (the agent's
     * name by default). Opened before the turn runs, the conversation needs no title from the model, and a first turn
     * that fails still lands where the next turn continues.
     */
    public function open(?string $title = null): string
    {
        return $this->id() ?? DB::connection((new AgenticConversation)->getConnectionName())->transaction(function () use ($title): string {
            $id = app(ConversationStore::class)->storeConversation(
                Conversation::participantType($this->participant),
                Conversation::participantKey($this->participant),
                $title ?? Str::headline(class_basename($this->agent)),
            );

            $kept = AgenticConversation::query()->createOrFirst($this->key(), ['conversation_id' => $id])->conversation_id;

            // A concurrent first turn that won the unique key keeps its conversation: this one continues it, and the
            // empty conversation it just stored goes.
            if ($kept !== $id) {
                Conversation::query()->whereKey($id)->delete();
            }

            return $kept;
        });
    }

    /**
     * The key: the participant, the tenant (null for none) and the agent class.
     *
     * @return array{participant_type: string, participant_id: mixed, tenant_type: string|null, tenant_id: mixed, agent: string}
     */
    private function key(): array
    {
        return [
            'participant_type' => $this->participant->getMorphClass(),
            'participant_id' => $this->participant->getKey(),
            'tenant_type' => $this->tenant?->getMorphClass(),
            'tenant_id' => $this->tenant?->getKey(),
            'agent' => $this->agent,
        ];
    }
}
