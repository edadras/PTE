<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @see docs/07-database-schema.md §9
 * @see docs/09-billing-and-plans.md §7
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            $table->string('number', 40)->unique();
            // The per-academy counter behind `number`. The unique index is what
            // actually guarantees no two concurrent issuers get the same one.
            $table->unsignedInteger('sequence');

            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();

            // Integer minor units of `currency`. total = amount + tax.
            $table->unsignedBigInteger('amount')->default(0);
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            // Basis points (900 = 9%), so the rate stays exact without floats.
            $table->unsignedSmallInteger('tax_rate_bp')->default(0);
            $table->string('currency', 3)->default('IRR');

            $table->string('status', 20)->default('draft');
            $table->json('lines')->nullable();
            $table->json('billing_details')->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('pdf_path', 255)->nullable();

            $table->timestamps();

            $table->unique(['academy_id', 'sequence']);
            $table->index(['academy_id', 'status', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
