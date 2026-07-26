<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalised dashboard source: one row per (academy, date, metric).
 *
 * Without it every dashboard load aggregates `answers`, which is the busiest
 * table in the platform (docs/07 §11). `value` is decimal rather than integer
 * because the same table stores counts, average scores and revenue in minor
 * units.
 *
 * @see docs/07-database-schema.md §11
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_stats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            $table->date('date');
            $table->string('metric', 48);
            $table->decimal('value', 20, 4)->default(0);
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(['academy_id', 'date', 'metric']);
            $table->index(['academy_id', 'metric', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_stats');
    }
};
