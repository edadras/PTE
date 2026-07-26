<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Super Admin actions. Explicitly NOT tenant-scoped (CONVENTIONS §3): the whole
 * point is to record what the platform did *to* an academy, including
 * impersonation and cross-tenant reads, so an academy must never be able to
 * scope it away.
 *
 * @see docs/02-roles-and-rbac.md §7 · docs/07-database-schema.md §10
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_audit_logs', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 64);
            $table->unsignedBigInteger('target_academy_id')->nullable();

            $table->json('payload')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['target_academy_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_logs');
    }
};
