<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @see docs/04-telegram-layer.md §6
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_flows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            $table->string('name', 150);
            $table->string('key', 64)->nullable();
            $table->text('description')->nullable();

            $table->string('trigger_type', 32)->default('command');
            $table->json('trigger_config')->nullable();

            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(false);
            $table->string('entry_node_key', 64)->nullable();

            // Overrides config('pte.telegram.*) per flow when the academy needs it.
            $table->unsignedSmallInteger('max_hops')->nullable();
            $table->unsignedInteger('wait_timeout_seconds')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['academy_id', 'status']);
            $table->index(['academy_id', 'trigger_type', 'is_active']);
            $table->index(['academy_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_flows');
    }
};
