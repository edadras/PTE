<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned prompts.
 *
 * `academy_id` is nullable on purpose: a NULL row is the platform default that
 * every academy inherits until it publishes its own version. The alternative —
 * copying twelve prompts into every new tenant — makes a platform-wide prompt
 * fix impossible to ship. Tenant isolation is preserved because the model only
 * ever widens the scope to "mine OR platform", never to another academy.
 *
 * @see docs/07-database-schema.md §8
 * @see docs/06-ai-layer.md §3
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_prompts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->nullable()->constrained('academies')->cascadeOnDelete();

            $table->string('key', 60);
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 20)->default('draft');

            $table->text('system_prompt')->nullable();
            $table->text('user_template');
            $table->json('output_schema')->nullable();
            $table->json('variables')->nullable();
            $table->string('model_hint', 120)->nullable();

            // A prompt may not be published until it has been run against real
            // samples (docs/06 §3, guardrails).
            $table->timestamp('tested_at')->nullable();
            $table->json('test_results')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['academy_id', 'key', 'version']);
            $table->index(['academy_id', 'key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prompts');
    }
};
