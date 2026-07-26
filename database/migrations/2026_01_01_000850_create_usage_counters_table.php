<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable mirror of the live Redis quota counters.
 *
 * Redis is the hot path; this table is what survives a Redis flush and what
 * reporting and invoicing read. RollUpUsageCounters reconciles the two.
 *
 * @see docs/07-database-schema.md §9
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_counters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            // 'YYYY-MM' — the billing period the counter belongs to.
            $table->char('period', 7);
            $table->string('metric', 40);

            $table->unsignedBigInteger('value')->default(0);
            // Snapshot of the plan limit at roll-up time; null = unlimited.
            $table->unsignedBigInteger('limit_value')->nullable();
            $table->timestamp('reconciled_at')->nullable();

            $table->timestamps();

            $table->unique(['academy_id', 'period', 'metric']);
            $table->index(['academy_id', 'metric', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
    }
};
