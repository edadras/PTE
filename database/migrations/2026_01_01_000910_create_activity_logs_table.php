<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant-visible audit trail.
 *
 * Append-only from the academy's point of view: there is no `updated_at` and no
 * soft delete, and the model refuses to update or delete. Retention (12 months,
 * `pte.retention.activity_logs`) is enforced by the platform sweep, never by the
 * customer whose actions it records — an audit trail the audited party can edit
 * is not evidence of anything.
 *
 * Range-partitioned monthly in production; that is a DBA task kept out of the
 * migration so the suite still runs on SQLite (CONVENTIONS §4).
 *
 * @see docs/02-roles-and-rbac.md §7 · docs/07-database-schema.md §10
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            $table->string('actor_type', 32)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label', 191)->nullable();

            $table->string('action', 64);

            $table->string('subject_type', 191)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['academy_id', 'created_at']);
            $table->index(['academy_id', 'subject_type', 'subject_id']);
            $table->index(['academy_id', 'action', 'created_at']);
            $table->index(['academy_id', 'actor_type', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
