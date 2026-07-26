<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Full prompt and response, Super Admin only, pruned after
 * config('pte.retention.ai_logs') days.
 *
 * This is the ONLY table allowed to hold a rendered prompt — everything else
 * (exceptions, application logs, ai_requests) carries identifiers instead.
 *
 * @see docs/07-database-schema.md §8
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();
            $table->foreignId('ai_request_id')->constrained('ai_requests')->cascadeOnDelete();

            $table->longText('rendered_prompt')->nullable();
            $table->longText('raw_response')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['academy_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_logs');
    }
};
