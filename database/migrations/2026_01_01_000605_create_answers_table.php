<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The busiest table in the platform: one row per submitted question, for both
 * practice and exam. It is deliberately polymorphic over session_type/session_id
 * rather than split in two, so scoring, reporting and difficulty calibration all
 * read from a single place.
 *
 * question_id / student_id / overridden_by point at tables owned by other
 * contexts. They carry indexes but no database-level foreign key: each context
 * ships its own migrations and this one must stay runnable on its own.
 *
 * @see docs/07-database-schema.md §7
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            $table->string('session_type', 16);
            $table->unsignedBigInteger('session_id');

            $table->unsignedBigInteger('question_id');
            $table->unsignedBigInteger('student_id');

            $table->json('answer_data')->nullable();
            $table->string('media_path', 255)->nullable();
            $table->text('transcript')->nullable();
            $table->json('transcript_meta')->nullable();

            $table->decimal('score', 6, 2)->nullable();
            $table->decimal('max_score', 6, 2)->nullable();
            $table->json('breakdown')->nullable();
            $table->json('feedback')->nullable();

            $table->unsignedBigInteger('ai_request_id')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();

            $table->string('scoring_status', 20)->default('pending');
            $table->string('scored_by', 16)->nullable();
            $table->timestamp('scored_at')->nullable();

            // Teacher override audit trail. original_ai_score is written once and
            // never touched again, so a disputed grade can always be traced back
            // to what the model actually said.
            $table->boolean('graded_manually')->default(false);
            $table->decimal('original_ai_score', 6, 2)->nullable();
            $table->text('override_reason')->nullable();
            $table->unsignedBigInteger('overridden_by')->nullable();

            $table->timestamps();

            $table->index(['academy_id', 'session_type', 'session_id']);
            $table->index(['academy_id', 'student_id', 'created_at']);
            $table->index(['academy_id', 'scoring_status']);
            $table->index(['academy_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answers');
    }
};
