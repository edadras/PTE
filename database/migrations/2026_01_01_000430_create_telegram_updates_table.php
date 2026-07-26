<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw webhook envelopes, kept 30 days for debugging and replay.
 *
 * In production this table is range-partitioned monthly on created_at — that is
 * a DBA task, deliberately kept out of the migration so the suite still runs on
 * SQLite (CONVENTIONS §4).
 *
 * @see docs/07-database-schema.md §5
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();
            $table->foreignId('telegram_bot_id')->constrained('telegram_bots')->cascadeOnDelete();

            $table->unsignedBigInteger('update_id');
            $table->string('type', 32)->default('unknown');
            $table->json('payload');

            $table->unsignedBigInteger('chat_id')->nullable();
            $table->unsignedBigInteger('telegram_user_id')->nullable();

            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);

            $table->timestamp('created_at')->nullable();

            // Second line of idempotency behind the Redis SETNX in the controller.
            $table->unique(['telegram_bot_id', 'update_id']);
            $table->index(['academy_id', 'created_at']);
            $table->index(['academy_id', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_updates');
    }
};
