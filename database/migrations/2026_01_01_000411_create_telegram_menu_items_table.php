<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buttons. `row`/`column` drive the keyboard grid, `visibility_rule` decides
 * whether a given student sees the button at all.
 *
 * @see docs/04-telegram-layer.md §5
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_menu_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();
            $table->foreignId('menu_id')->constrained('telegram_menus')->cascadeOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();

            $table->string('label', 120);
            $table->string('icon', 16)->nullable();

            $table->string('action_type', 32);
            $table->json('action_payload')->nullable();
            $table->json('visibility_rule')->nullable();

            $table->unsignedSmallInteger('row')->default(0);
            $table->unsignedSmallInteger('column')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_enabled')->default(true);

            $table->timestamps();

            $table->index(['academy_id', 'menu_id', 'sort_order']);
            $table->index(['academy_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_menu_items');
    }
};
