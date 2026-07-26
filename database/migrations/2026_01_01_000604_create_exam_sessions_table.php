<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @see docs/07-database-schema.md §7
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();

            $table->unsignedBigInteger('student_id');
            $table->unsignedSmallInteger('attempt_number')->default(1);

            $table->string('status', 20)->default('in_progress');

            $table->unsignedBigInteger('current_section_id')->nullable();

            // Immutable copy of the paper (sections, questions, per-question
            // payload and scores) as it stood at start. If the academy edits the
            // exam afterwards the student's result stays reproducible.
            $table->json('snapshot')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('scored_at')->nullable();

            $table->decimal('total_score', 8, 2)->nullable();
            $table->json('section_scores')->nullable();
            $table->boolean('passed')->nullable();

            $table->timestamps();

            $table->index(['academy_id', 'exam_id', 'student_id']);
            $table->index(['academy_id', 'status', 'expires_at']);
            $table->index(['academy_id', 'student_id', 'created_at']);
            $table->unique(['exam_id', 'student_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_sessions');
    }
};
