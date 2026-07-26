<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materialised per-student, per-question-type aggregates so the dashboard and
 * the bot never have to scan the answers table.
 *
 * docs/07 specifies a composite primary key; we keep a surrogate `id` plus the
 * same combination as a unique index, because Eloquent cannot address a row by
 * a composite key.
 *
 * @see docs/07-database-schema.md §4
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();

            $table->string('module_key', 64);
            $table->string('question_type', 64);

            $table->unsignedInteger('attempts')->default(0);
            $table->decimal('avg_score', 6, 2)->nullable();
            $table->decimal('best_score', 6, 2)->nullable();
            $table->decimal('last_score', 6, 2)->nullable();

            $table->unsignedSmallInteger('streak_days')->default(0);
            $table->unsignedBigInteger('total_time_seconds')->default(0);

            $table->timestamp('last_practiced_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['academy_id', 'student_id', 'module_key', 'question_type'],
                'student_progress_unique'
            );
            $table->index(['academy_id', 'module_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_progress');
    }
};
