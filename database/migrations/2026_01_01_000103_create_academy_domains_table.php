<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hostname -> tenant mapping. Read *before* tenant resolution, therefore this
 * table is never itself tenant-scoped.
 *
 * @see docs/01-multi-tenancy.md §4.2
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academy_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            $table->string('hostname', 191)->unique();
            $table->string('type', 20)->default('subdomain');
            $table->boolean('is_primary')->default(false);

            $table->timestamp('verified_at')->nullable();
            $table->string('verification_token', 64)->nullable();
            $table->string('ssl_status', 20)->default('pending');

            $table->timestamps();

            $table->index(['academy_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academy_domains');
    }
};
