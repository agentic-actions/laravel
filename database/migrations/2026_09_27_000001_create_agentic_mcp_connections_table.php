<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Passport's connection, as its own migrations use, so a connection and the tokens it names change together.
     */
    public function getConnection(): ?string
    {
        return config('passport.connection');
    }

    /**
     * Run the migrations: one row per OAuth client and person, naming the MCP URL they approved (a tenant, or none for
     * the base path) and the scopes they approved there. No foreign key: Passport's client table may use another key
     * type, and revoking deletes the row.
     */
    public function up(): void
    {
        Schema::create('agentic_mcp_connections', function (Blueprint $table) {
            $table->id();
            $table->string('client_id', 100);
            $table->morphs('user');
            $table->nullableMorphs('tenant');
            $table->json('scopes');
            $table->timestamps();

            $table->unique(['client_id', 'user_type', 'user_id'], 'agentic_mcp_connections_key_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agentic_mcp_connections');
    }
};
