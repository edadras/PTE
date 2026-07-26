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
        Schema::create('question_banks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            $table->string('name', 150);
            $table->string('module_key', 40)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);

            // Denormalised so bank listings never have to count a million rows.
            $table->unsignedInteger('question_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['academy_id', 'module_key']);
            $table->index(['academy_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_banks');
    }
};
