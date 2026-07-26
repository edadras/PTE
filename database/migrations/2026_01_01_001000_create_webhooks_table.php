<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbound webhook endpoints an academy registers for its own CRM / LMS.
 *
 * @see docs/08-api-and-integrations.md §5
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            $table->string('name', 100);
            $table->string('url', 2048);

            // Encrypted at rest (docs/12 §2) — the HMAC key for X-PTE-Signature.
            $table->text('secret');

            $table->json('events');
            $table->boolean('is_active')->default(true);

            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);

            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_error', 500)->nullable();

            $table->timestamp('disabled_at')->nullable();
            $table->string('disabled_reason', 191)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['academy_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};
