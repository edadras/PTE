<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scoring weights. NULL academy_id = platform default, same reasoning as
 * ai_prompts.
 *
 * @see docs/07-database-schema.md §8
 * @see docs/06-ai-layer.md §4
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_rubrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->nullable()->constrained('academies')->cascadeOnDelete();

            $table->string('task_key', 60);
            $table->string('name', 120);
            $table->unsignedSmallInteger('version')->default(1);
            $table->boolean('is_active')->default(true);

            // [{key, label, weight, guidance}, ...] — weights must sum to 100,
            // enforced on save by RubricEngine.
            $table->json('criteria');

            $table->unsignedSmallInteger('scale_min')->default(0);
            $table->unsignedSmallInteger('scale_max')->default(90);
            $table->string('rounding', 20)->default('nearest');

            $table->timestamps();

            $table->unique(['academy_id', 'task_key', 'version']);
            $table->index(['academy_id', 'task_key', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_rubrics');
    }
};
