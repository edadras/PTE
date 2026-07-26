<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2C: what a student bought from an academy.
 *
 * @see docs/07-database-schema.md §9
 * @see docs/09-billing-and-plans.md §5
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('course_id')->nullable();

            $table->string('plan_name', 120);
            $table->string('plan_key', 60)->nullable();
            $table->string('status', 20)->default('pending');

            // All money in integer minor units of `currency`.
            $table->unsignedBigInteger('price');
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('credit_amount')->default(0);
            $table->string('currency', 3)->default('IRR');

            $table->unsignedSmallInteger('duration_days')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->boolean('auto_renew')->default(false);

            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();

            $table->json('entitlements')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['academy_id', 'student_id', 'status']);
            $table->index(['academy_id', 'status', 'expires_at']);
            $table->index(['academy_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_subscriptions');
    }
};
