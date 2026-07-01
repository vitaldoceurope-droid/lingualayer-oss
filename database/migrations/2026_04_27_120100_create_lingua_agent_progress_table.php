<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-language progress snapshot for the autonomous agent. The dashboard at
 * /lingua/quality polls this table (via /lingua/quality/progress) every few
 * seconds to render real-time progress bars and an ETA per target language.
 *
 * One row per target_lang (UNIQUE). Updated by ProgressTracker as warm jobs
 * complete each page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lingua_agent_progress', function (Blueprint $table) {
            $table->id();

            $table->string('target_lang', 8);

            $table->unsignedInteger('pages_total')->default(0);
            $table->unsignedInteger('pages_translated')->default(0);
            $table->unsignedInteger('pages_pending')->default(0);
            $table->unsignedInteger('pages_failed')->default(0);

            $table->unsignedInteger('fragments_total')->default(0);
            $table->unsignedInteger('fragments_translated')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Computed each time recordPageCompleted() is called using a rolling
            // average of seconds-per-page. Null while pages_translated == 0.
            $table->unsignedInteger('estimated_seconds_remaining')->nullable();

            $table->string('last_page_completed', 512)->nullable();

            // Drives the badge in the dashboard. 'idle' is the resting state
            // before any scan; 'running' during translation; 'completed' at
            // 100%; 'error' if the language run aborted.
            $table->enum('status', ['idle', 'running', 'completed', 'error'])
                ->default('idle');

            $table->timestamps();

            $table->unique('target_lang');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lingua_agent_progress');
    }
};
