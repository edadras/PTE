<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_flow_edges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained('telegram_flows')->cascadeOnDelete();

            $table->string('from_node', 64);
            $table->string('to_node', 64);

            $table->json('condition')->nullable();
            $table->string('label', 120)->nullable();

            // Lowest sort_order wins when several conditions match.
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index(['academy_id', 'flow_id', 'from_node']);
            $table->index(['flow_id', 'to_node']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_flow_edges');
    }
};
