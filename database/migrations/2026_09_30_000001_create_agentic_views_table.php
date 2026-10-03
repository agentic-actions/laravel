<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The connection laravel/ai keeps its conversations on, beside them.
     */
    public function getConnection(): ?string
    {
        return config('ai.conversations.connection', config('database.default'));
    }

    /**
     * Run the migrations: one row per table a person was shown in a stored conversation, by its tool call, with the
     * input and fixed keys a refresh runs again. No foreign key: laravel/ai's tables may live elsewhere, and
     * model:prune removes the rows of a conversation that is gone.
     */
    public function up(): void
    {
        Schema::create('agentic_views', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('conversation_id', 36);
            $table->string('tool_call_id');
            $table->morphs('participant');
            $table->nullableMorphs('tenant');
            $table->string('action');
            $table->json('input');
            $table->json('fixed');
            $table->json('table');
            $table->timestamps();

            $table->unique(['conversation_id', 'tool_call_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agentic_views');
    }
};
