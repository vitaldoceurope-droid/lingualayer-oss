<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row table holding the autonomous agent's last activity timestamps
 * and the routes signature it last saw. The agent uses these to decide
 * whether a fresh discovery scan or change check is needed without
 * re-scanning unnecessarily.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lingua_agent_state', function (Blueprint $table) {
            $table->id();

            $table->timestamp('last_full_scan_at')->nullable();
            $table->timestamp('last_change_check_at')->nullable();
            $table->timestamp('last_quality_check_at')->nullable();

            $table->unsignedInteger('pages_known')->default(0);

            // SHA256 hex (64 chars) of the sorted, GET-only public route list.
            // When this changes, the agent knows a deploy added/removed pages
            // and triggers a full scan instead of a change check.
            $table->string('routes_signature', 64)->nullable();

            $table->boolean('enabled')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lingua_agent_state');
    }
};
