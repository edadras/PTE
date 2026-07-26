<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @see docs/07-database-schema.md §6
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();
            $table->foreignId('bank_id')->constrained('question_banks')->cascadeOnDelete();

            $table->string('module_key', 40);
            $table->string('type', 20);
            $table->string('difficulty', 10)->default('medium');

            // Derived from real answers, not from the author's opinion.
            $table->decimal('difficulty_index', 3, 2)->default(0.50);

            $table->string('title', 190)->nullable();
            $table->json('content');
            $table->json('correct_answer')->nullable();
            $table->json('metadata')->nullable();
            $table->json('tags')->nullable();

            $table->string('status', 20)->default('draft');

            $table->unsignedInteger('usage_count')->default(0);
            $table->decimal('avg_score', 6, 2)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();

            // Set by bulk import so a whole import can be rolled back later.
            $table->uuid('import_batch_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['academy_id', 'module_key', 'type', 'status']);
            $table->index(['academy_id', 'bank_id', 'status']);
            $table->index(['academy_id', 'type', 'difficulty_index']);
            $table->index(['academy_id', 'import_batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
