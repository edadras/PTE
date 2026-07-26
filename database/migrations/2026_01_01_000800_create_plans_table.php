<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform plans. NON-tenant: every academy points at the same catalogue.
 *
 * @see docs/07-database-schema.md §9
 * @see docs/09-billing-and-plans.md §1
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();

            // Money is stored in integer MINOR UNITS of `currency`.
            // IRR has no subunit in practice, so one minor unit = one Rial.
            // Null means "negotiated" (Enterprise) — not "free".
            $table->unsignedBigInteger('price_monthly')->nullable();
            $table->unsignedBigInteger('price_yearly')->nullable();
            $table->string('currency', 3)->default('IRR');

            $table->json('limits');
            $table->json('features');

            $table->boolean('is_public')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'is_public', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
