<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment attempts for both money flows (B2B and B2C).
 *
 * No card data is ever stored here — only gateway references. See docs/09 §7.
 *
 * @see docs/07-database-schema.md §9
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            $table->string('payable_type', 120)->nullable();
            $table->unsignedBigInteger('payable_id')->nullable();

            // Integer minor units of `currency`.
            $table->unsignedBigInteger('amount');
            // Platform commission on B2C sales routed through our gateway
            // (docs/09 §5) — minor units of the same currency.
            $table->unsignedBigInteger('platform_fee_amount')->default(0);
            $table->unsignedBigInteger('refunded_amount')->default(0);
            $table->string('currency', 3)->default('IRR');

            $table->string('gateway', 30);
            // Gateway-side transaction id, known only after a verified payment.
            $table->string('gateway_ref', 191)->nullable();
            // Pre-payment token (ZarinPal authority, Zibal trackId, Stripe session).
            $table->string('authority', 191)->nullable();

            $table->string('status', 20)->default('pending');
            $table->string('description', 255)->nullable();
            $table->string('failure_code', 60)->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['academy_id', 'status', 'created_at']);
            $table->index(['academy_id', 'payable_type', 'payable_id']);
            $table->index('gateway_ref');
            $table->index('authority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
