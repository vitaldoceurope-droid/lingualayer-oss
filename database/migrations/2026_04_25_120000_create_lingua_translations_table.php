<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lingua_translations', function (Blueprint $table) {
            $table->id();

            // SHA256 of (source_text + '|' + source_lang). Combined with target_lang
            // it uniquely identifies a translation, regardless of which page hit it.
            $table->char('source_hash', 64);

            $table->text('source_text');
            $table->string('source_lang', 8);
            $table->string('target_lang', 8);
            $table->text('translated_text');

            // 'gemini-2.5-flash', 'cache_migration', 'manual', etc.
            $table->string('model_used', 50)->nullable();

            $table->unsignedTinyInteger('score')->nullable();
            $table->string('page_url', 512)->nullable();
            $table->string('element_path', 256)->nullable();
            $table->unsignedInteger('times_used')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_obsolete')->default(false);
            $table->timestamps();

            // A given source string in a given source_lang can have exactly one
            // translation per target_lang — upsert by this composite key.
            $table->unique(['source_hash', 'target_lang']);

            // Common scan: "give me everything for fr that is still active"
            $table->index(['target_lang', 'is_obsolete']);

            // Cleanup job sorts by this to find stale rows.
            $table->index('last_seen_at');

            // Change-detection scans translations on the same page.
            $table->index('page_url');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lingua_translations');
    }
};
