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
        Schema::create('exams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            $table->string('title', 190);
            $table->text('description')->nullable();

            $table->unsignedSmallInteger('duration_minutes')->default(0);
            $table->decimal('total_score', 8, 2)->default(90);
            $table->decimal('passing_score', 8, 2)->nullable();

            // rules: ordered_sections, allow_back, shuffle_questions, show_timer,
            // autosave, instant_result, require_teacher_approval.
            $table->json('rules')->nullable();

            // availability: opens_at, closes_at, max_attempts, audience.
            $table->json('availability')->nullable();

            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();

            // Cross-context reference (users live in Identity) — no FK on purpose,
            // see the note in the answers migration.
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['academy_id', 'status']);
            $table->index(['academy_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exams');
    }
};
