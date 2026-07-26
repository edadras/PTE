<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which capability packs a tenant has switched on.
 *
 * `module_key` intentionally carries no foreign key to `modules`: an academy
 * may keep a module enabled while the platform temporarily unregisters it.
 *
 * @see docs/07-database-schema.md §2
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academy_modules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            $table->string('module_key', 64);
            $table->boolean('is_enabled')->default(true);
            $table->json('settings')->nullable();
            $table->timestamp('enabled_at')->nullable();

            $table->timestamps();

            $table->unique(['academy_id', 'module_key']);
            $table->index(['academy_id', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academy_modules');
    }
};
