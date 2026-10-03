<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The connection laravel/ai keeps its conversations on, so the foreign key below can reach them.
     */
    public function getConnection(): ?string
    {
        return config('ai.conversations.connection', config('database.default'));
    }

    /**
     * Run the migrations: one row per participant, tenant and agent class, naming the laravel/ai conversation they
     * continue. The agent column is 150 characters, so the unique key fits MySQL's 3,072-byte limit.
     */
    public function up(): void
    {
        Schema::create('agentic_conversations', function (Blueprint $table) {
            $table->id();
            $table->morphs('participant');
            $table->nullableMorphs('tenant');
            $table->string('agent', 150);
            $table->string('conversation_id', 36)->unique();
            $table->timestamps();

            $table->unique(['participant_type', 'participant_id', 'tenant_type', 'tenant_id', 'agent'], 'agentic_conversations_key_unique');
            $table->foreign('conversation_id')
                ->references('id')
                ->on(config('ai.conversations.tables.conversations', 'agent_conversations'))
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agentic_conversations');
    }
};
