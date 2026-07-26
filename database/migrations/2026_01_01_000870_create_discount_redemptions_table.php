<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per actual use of a code — the audit trail behind
 * discount_codes.redemptions_count.
 *
 * @see docs/09-billing-and-plans.md §5
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();
            $table->foreignId('discount_code_id')->constrained('discount_codes')->cascadeOnDelete();

            $table->unsignedBigInteger('student_id')->nullable();
            $table->foreignId('student_subscription_id')->nullable()
                ->constrained('student_subscriptions')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();

            // Minor units of `currency`.
            $table->unsignedBigInteger('amount_discounted');
            $table->string('currency', 3)->default('IRR');

            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();

            $table->index(['academy_id', 'discount_code_id']);
            $table->index(['academy_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_redemptions');
    }
};
