<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nodes are addressed by `node_key`, not by id, so a flow can be exported,
 * duplicated or version-bumped without rewriting every edge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_flow_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained('telegram_flows')->cascadeOnDelete();

            $table->string('node_key', 64);
            $table->string('type', 32);
            $table->string('title', 150)->nullable();
            $table->json('config')->nullable();

            $table->integer('position_x')->default(0);
            $table->integer('position_y')->default(0);

            $table->timestamps();

            $table->unique(['flow_id', 'node_key']);
            $table->index(['academy_id', 'flow_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_flow_nodes');
    }
};
