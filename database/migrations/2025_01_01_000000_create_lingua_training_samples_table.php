<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lingua_training_samples', function (Blueprint $table) {
            $table->id();
            $table->string('source_lang', 8);
            $table->string('target_lang', 8);
            $table->string('source_text', 1000);
            $table->string('translated_text', 1000);
            $table->tinyInteger('score')->unsigned();
            $table->string('context', 64)->nullable();
            $table->boolean('validated')->default(false);
            $table->timestamps();

            $table->index(['source_lang', 'target_lang', 'score']);
            $table->index(['validated', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lingua_training_samples');
    }
};
