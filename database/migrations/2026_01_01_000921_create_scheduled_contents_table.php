<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anything an academy wants to happen at a stated wall-clock time: publish an
 * exam, send the daily practice nudge at 09:00, remind before a test.
 *
 * `scheduled_at` is stored in UTC like every other timestamp (docs/07 §1), and
 * `timezone` records the academy zone the human actually chose. Both are needed:
 * without the zone, a rule like "every day at 09:00" silently drifts by an hour
 * at every DST change, and the next occurrence cannot be recomputed correctly.
 *
 * The second index omits `academy_id` on purpose — the every-minute dispatcher
 * is a platform sweep across all tenants, and prefixing it with the tenant would
 * make that scan useless.
 *
 * @see docs/05-modules-exams-practice.md §6 · docs/07-database-schema.md §10
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_contents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            $table->string('type', 32);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('audience')->nullable();
            $table->json('payload')->nullable();

            $table->timestamp('scheduled_at');
            $table->string('repeat_rule', 64)->nullable();
            $table->string('timezone', 64)->default('UTC');

            $table->string('status', 16)->default('pending');
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('run_count')->default(0);
            $table->text('error')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index(['academy_id', 'status', 'scheduled_at']);
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_contents');
    }
};
