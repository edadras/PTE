<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per attempt-set: the delivery id travels in `X-PTE-Delivery` so the
 * receiving system can deduplicate retries of the same event.
 *
 * @see docs/08-api-and-integrations.md §5
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();
            $table->foreignId('webhook_id')->constrained('webhooks')->cascadeOnDelete();

            $table->string('delivery_id', 26)->unique();
            $table->string('event', 64);

            $table->json('payload');

            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempt')->default(0);

            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();

            $table->timestamps();

            $table->index(['academy_id', 'webhook_id', 'created_at']);
            $table->index(['academy_id', 'event', 'created_at']);
            $table->index(['academy_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
