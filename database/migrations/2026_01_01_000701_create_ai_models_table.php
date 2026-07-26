<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform-wide model catalogue — deliberately NOT tenant scoped
 * (CONVENTIONS §3). Prices and capabilities are ours to maintain; academies
 * only choose from this list.
 *
 * @see docs/07-database-schema.md §8
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table): void {
            $table->id();

            $table->string('provider', 30);
            $table->string('model_key', 120)->unique();
            $table->string('display_name', 120);
            $table->json('capabilities')->nullable();

            // Per one million tokens, in `currency`. Six decimals because cheap
            // models are priced in fractions of a cent.
            $table->decimal('input_price_per_1m', 12, 6)->default(0);
            $table->decimal('output_price_per_1m', 12, 6)->default(0);

            // ASR vendors bill by audio minute, not by token; without this the
            // transcription half of the bill would be invisible.
            $table->decimal('price_per_audio_minute', 12, 6)->nullable();

            $table->string('currency', 3)->default('USD');

            $table->unsignedInteger('max_input_tokens')->nullable();
            $table->unsignedInteger('max_output_tokens')->nullable();

            $table->boolean('is_active')->default(true);
            $table->json('is_default_for')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['provider', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
