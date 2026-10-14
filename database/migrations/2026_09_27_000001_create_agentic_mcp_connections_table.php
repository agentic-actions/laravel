<?php

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;

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
     * the base path) and the scopes they approved there. client_id has the type of Passport's client key, so a join or
     * whereHas() compares like with like on every database: a uuid (Passport's default), an unsigned big integer for
     * incrementing ids, a string otherwise. No foreign key: revoking deletes the row.
     */
    public function up(): void
    {
        $client = class_exists(Passport::class) ? Passport::client() : null;

        Schema::create('agentic_mcp_connections', function (Blueprint $table) use ($client) {
            $table->id();

            match (true) {
                $client?->getIncrementing() === true => $table->unsignedBigInteger('client_id'),
                $client !== null && in_array(HasUuids::class, class_uses_recursive($client), true) && in_array($client->getKeyName(), $client->uniqueIds(), true) => $table->uuid('client_id'),
                default => $table->string('client_id', 100),
            };

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
