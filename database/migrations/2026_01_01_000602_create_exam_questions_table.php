<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit question membership of a section. In `manual` mode these are the
 * paper itself; in `pool` mode they are the candidate pool a per-student subset
 * is drawn from.
 *
 * @see docs/07-database-schema.md §7
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();
            $table->foreignId('exam_section_id')->constrained('exam_sections')->cascadeOnDelete();

            $table->unsignedBigInteger('question_id');

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->decimal('score', 8, 2)->nullable();

            $table->timestamps();

            $table->index(['academy_id', 'exam_section_id', 'sort_order']);
            $table->index(['academy_id', 'question_id']);
            $table->unique(['exam_section_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_questions');
    }
};
