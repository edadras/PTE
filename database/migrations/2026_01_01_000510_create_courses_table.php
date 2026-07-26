<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @see docs/07-database-schema.md §6
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            $table->string('title', 190);
            $table->string('slug', 120);
            $table->text('description')->nullable();
            $table->string('module_key', 40)->nullable();

            $table->string('cover_path', 255)->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency', 3)->default('IRR');
            $table->unsignedSmallInteger('duration_days')->nullable();

            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['academy_id', 'slug']);
            $table->index(['academy_id', 'status', 'sort_order']);
            $table->index(['academy_id', 'module_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
