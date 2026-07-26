<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Student ↔ staff conversations that outlive a single Telegram session.
 *
 * `assigned_to` points at users, which is a platform table: a support agent may
 * work for more than one academy, so the row carries no tenant of its own.
 *
 * @see docs/07-database-schema.md §10
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            $table->unsignedBigInteger('student_id')->nullable();

            $table->string('subject', 191);
            $table->string('status', 16)->default('open');
            $table->string('priority', 16)->default('normal');
            $table->string('source', 24)->default('telegram');

            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();

            $table->timestamp('last_reply_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['academy_id', 'status', 'last_reply_at']);
            $table->index(['academy_id', 'student_id', 'created_at']);
            $table->index(['academy_id', 'assigned_to', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
