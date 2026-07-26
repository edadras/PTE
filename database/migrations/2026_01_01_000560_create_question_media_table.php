<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @see docs/07-database-schema.md §6
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();

            $table->string('kind', 10);
            $table->string('s3_path', 255);
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('transcript')->nullable();

            // Re-sending a cached file_id costs one API call instead of an upload,
            // but the id is only valid for the bot that produced it.
            $table->string('telegram_file_id', 190)->nullable();
            $table->unsignedBigInteger('telegram_bot_id')->nullable();
            $table->timestamp('cached_at')->nullable();

            $table->timestamps();

            $table->index(['academy_id', 'question_id']);
            $table->index(['academy_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_media');
    }
};
