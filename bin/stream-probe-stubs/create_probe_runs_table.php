<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per slow tool call: its run, when it ran, and connection_status() when it finished.
     */
    public function up(): void
    {
        Schema::create('probe_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run')->index();
            $table->string('tool');
            $table->double('started_at');
            $table->double('finished_at');
            $table->unsignedTinyInteger('connection_status');
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('probe_runs');
    }
};
