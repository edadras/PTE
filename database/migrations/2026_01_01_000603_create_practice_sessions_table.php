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
        Schema::create('practice_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            $table->unsignedBigInteger('student_id');

            $table->string('module_key', 40);
            $table->string('question_type', 16)->nullable();

            $table->string('status', 20)->default('in_progress');

            $table->unsignedSmallInteger('total_questions')->default(0);
            $table->unsignedSmallInteger('answered')->default(0);
            $table->decimal('total_score', 8, 2)->nullable();
            $table->decimal('max_score', 8, 2)->nullable();

            // The drawn queue, frozen at start. Without it a student who loses
            // connection mid-session would be re-drawn a different set and the
            // "question 3 of 5" counter would lie.
            $table->json('question_ids')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->timestamps();

            $table->index(['academy_id', 'student_id', 'created_at']);
            $table->index(['academy_id', 'module_key', 'created_at']);
            $table->index(['academy_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_sessions');
    }
};
