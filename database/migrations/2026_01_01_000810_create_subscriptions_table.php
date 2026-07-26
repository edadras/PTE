<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2B: the academy's subscription to the platform.
 *
 * @see docs/07-database-schema.md §9
 * @see docs/09-billing-and-plans.md §3
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans');

            $table->string('status', 20)->default('trialing');
            $table->string('billing_cycle', 10)->default('monthly');

            // Integer minor units of `currency` — the price locked in at signup.
            $table->unsignedBigInteger('price')->default(0);
            $table->string('currency', 3)->default('IRR');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();

            // When the dunning clock started. suspended = past_due_at + grace.
            $table->timestamp('past_due_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // Which reminders (7/3/1) have already gone out, so a re-run of the
            // scheduler never spams the customer twice.
            $table->json('reminders_sent')->nullable();

            $table->string('gateway', 30)->nullable();
            $table->string('gateway_subscription_id', 191)->nullable();
            $table->boolean('auto_renew')->default(true);

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['academy_id', 'status']);
            $table->index(['academy_id', 'current_period_end']);
            $table->index('current_period_end');
            $table->index('gateway_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
