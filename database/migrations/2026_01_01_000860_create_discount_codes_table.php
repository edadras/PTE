<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @see docs/09-billing-and-plans.md §5
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            $table->string('code', 60);
            $table->string('description', 255)->nullable();

            $table->string('type', 20)->default('percentage');
            // percentage: basis points (1000 = 10%). fixed: minor units of `currency`.
            $table->unsignedBigInteger('value');
            $table->string('currency', 3)->default('IRR');
            // Ceiling for percentage codes, in minor units. Null = no ceiling.
            $table->unsignedBigInteger('max_discount_amount')->nullable();
            $table->unsignedBigInteger('min_order_amount')->default(0);

            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redemptions_count')->default(0);
            $table->unsignedInteger('per_student_limit')->default(1);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);

            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['academy_id', 'code']);
            $table->index(['academy_id', 'is_active', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_codes');
    }
};
