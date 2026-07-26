<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bridge between a Telegram user and a Student.
 *
 * A chat exists before a student record does (someone can /start and never
 * register), so `student_id` is nullable and filled in on linking.
 *
 * @see docs/07-database-schema.md §4
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('telegram_identities')) {
            return;
        }

        Schema::create('telegram_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            // No FK: the students table is owned by the Identity context and an
            // identity legitimately outlives / precedes its student row.
            $table->unsignedBigInteger('student_id')->nullable();

            $table->unsignedBigInteger('telegram_user_id');
            $table->unsignedBigInteger('chat_id');

            $table->string('username', 64)->nullable();
            $table->string('first_name', 191)->nullable();
            $table->string('last_name', 191)->nullable();
            $table->string('language_code', 12)->nullable();

            $table->boolean('is_bot')->default(false);
            $table->boolean('is_blocked')->default(false);
            $table->timestamp('blocked_at')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('last_interaction_at')->nullable();

            // Consent capture required on first /start (docs/12 §4).
            $table->timestamp('consented_at')->nullable();
            $table->string('consent_version', 32)->nullable();

            $table->timestamps();

            $table->unique(['academy_id', 'telegram_user_id']);
            $table->index(['academy_id', 'chat_id']);
            $table->index(['academy_id', 'student_id']);
            $table->index(['academy_id', 'is_blocked']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_identities');
    }
};
