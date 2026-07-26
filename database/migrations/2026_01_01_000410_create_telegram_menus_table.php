<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @see docs/04-telegram-layer.md §5
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_menus', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('key', 64)->nullable();
            $table->string('type', 20)->default('main');
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(false);
            $table->text('header_text')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['academy_id', 'type', 'is_active']);
            $table->index(['academy_id', 'status']);
            $table->index(['academy_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_menus');
    }
};
