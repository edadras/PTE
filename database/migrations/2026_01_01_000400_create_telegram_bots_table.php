<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One bot per academy. `public_id` — not the numeric id — appears in the webhook
 * URL so the endpoint cannot be enumerated.
 *
 * @see docs/04-telegram-layer.md §2, docs/07-database-schema.md §5
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_bots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            $table->ulid('public_id')->unique();

            // Encrypted at rest; ciphertext is far longer than the plaintext.
            $table->text('token')->nullable();
            $table->char('token_last4', 4)->nullable();

            $table->unsignedBigInteger('bot_user_id')->nullable();
            $table->string('username', 64)->nullable();
            $table->string('first_name', 191)->nullable();

            $table->text('webhook_secret')->nullable();
            $table->string('webhook_url', 191)->nullable();
            $table->timestamp('webhook_registered_at')->nullable();

            $table->boolean('is_active')->default(false);
            $table->string('health_status', 20)->default('ok');
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('pending_update_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_message_at')->nullable();

            $table->text('payments_provider_token')->nullable();

            $table->json('settings')->nullable();

            $table->timestamps();

            // Phase 1: a single bot per academy.
            $table->unique('academy_id');

            // Guards against two academies connecting the same BotFather bot.
            $table->index('bot_user_id');
            $table->index(['academy_id', 'is_active']);
            $table->index(['health_status', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_bots');
    }
};
