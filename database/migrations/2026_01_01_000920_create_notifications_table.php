<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Our own multi-channel outbox — deliberately not Laravel's `notifications`
 * table shape (uuid/read_at only). We need the channel, the scheduled time, the
 * delivery status and the provider error per row, because "did the student
 * actually get the reminder" is a support question asked every day.
 *
 * Laravel's own notification table is not installed; if it ever is, it must be
 * given a different table name.
 *
 * @see docs/07-database-schema.md §10
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            $table->string('notifiable_type', 191);
            $table->unsignedBigInteger('notifiable_id');

            $table->string('channel', 16);
            $table->string('type', 64);
            $table->json('data')->nullable();

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->string('status', 16)->default('pending');
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);

            $table->timestamps();

            $table->index(['academy_id', 'notifiable_type', 'notifiable_id']);
            $table->index(['academy_id', 'status', 'scheduled_at']);
            $table->index(['academy_id', 'channel', 'status']);
            $table->index(['academy_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
