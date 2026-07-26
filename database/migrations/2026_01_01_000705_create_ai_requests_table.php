<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The billing and reporting ledger: one row per provider call, successful or
 * not. Never carries prompt or response content — that lives in ai_logs for
 * seven days only.
 *
 * Monthly RANGE partitioning is a production DBA task (docs/07 §11); it is
 * deliberately absent here so the schema stays portable to SQLite for tests.
 *
 * @see docs/07-database-schema.md §8
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            // No FK: students and answers are owned by other contexts whose
            // migrations may not be present in a partial deployment, and a log
            // row must survive the deletion of what it describes.
            $table->unsignedBigInteger('student_id')->nullable();
            $table->unsignedBigInteger('answer_id')->nullable();

            $table->string('task_key', 60);
            $table->string('provider', 30);
            $table->string('model_key', 120);
            $table->unsignedSmallInteger('prompt_version')->nullable();

            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('audio_minutes', 10, 4)->nullable();

            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->decimal('cost_local', 14, 2)->nullable();
            $table->string('currency', 3)->default('USD');

            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('status', 20);

            $table->boolean('cache_hit')->default(false);
            $table->boolean('fallback_used')->default(false);
            $table->boolean('byok')->default(false);
            $table->string('error_code', 60)->nullable();
            $table->string('request_id', 64)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['academy_id', 'created_at']);
            $table->index(['academy_id', 'task_key', 'created_at']);
            $table->index(['academy_id', 'model_key', 'created_at']);
            $table->index(['academy_id', 'answer_id']);
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
