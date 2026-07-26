<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversation history, mainly so support can see what the student saw.
 * Retention 90 days; partitioned monthly in production (see 000430).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();
            $table->foreignId('telegram_bot_id')->nullable()->constrained('telegram_bots')->nullOnDelete();

            $table->unsignedBigInteger('student_id')->nullable();
            $table->unsignedBigInteger('chat_id');

            $table->string('direction', 3);
            $table->string('message_type', 32)->default('text');
            $table->text('content')->nullable();
            $table->json('meta')->nullable();

            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->string('status', 20)->default('sent');
            $table->text('error')->nullable();

            $table->unsignedBigInteger('broadcast_id')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['academy_id', 'student_id', 'created_at']);
            $table->index(['academy_id', 'chat_id', 'created_at']);
            $table->index(['academy_id', 'broadcast_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_messages');
    }
};
