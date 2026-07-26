<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (academy, task): which model, which chain, whose key.
 *
 * @see docs/07-database-schema.md §8
 * @see docs/06-ai-layer.md §2
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academy_ai_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            $table->string('task_key', 60);
            $table->string('provider', 30);
            $table->string('model_key', 120);

            $table->decimal('temperature', 3, 2)->nullable();
            $table->unsignedInteger('max_output_tokens')->nullable();
            $table->json('fallback_chain')->nullable();

            // BYOK: the key itself is encrypted at rest and never read back into
            // the UI — only api_key_last4 is ever displayed (docs/06 §2).
            $table->boolean('use_own_key')->default(false);
            $table->text('api_key')->nullable();
            $table->string('api_key_last4', 8)->nullable();

            $table->boolean('cache_enabled')->default(true);
            $table->boolean('economy_mode')->default(false);

            $table->timestamps();

            $table->unique(['academy_id', 'task_key']);
            $table->index(['academy_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academy_ai_settings');
    }
};
