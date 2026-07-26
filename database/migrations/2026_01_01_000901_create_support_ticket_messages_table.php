<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One turn in a ticket. `is_internal` marks a staff note the student never sees,
 * which is what stops agents from opening a second, invisible channel elsewhere.
 *
 * @see docs/07-database-schema.md §10
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_ticket_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();

            $table->string('sender_type', 16);
            $table->unsignedBigInteger('sender_id')->nullable();

            $table->text('content');
            $table->json('attachments')->nullable();
            $table->boolean('is_internal')->default(false);

            $table->timestamp('created_at')->nullable();

            $table->index(['academy_id', 'ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
    }
};
