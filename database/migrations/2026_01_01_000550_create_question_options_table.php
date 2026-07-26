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
        Schema::create('question_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();

            $table->string('option_key', 8);
            $table->text('text');
            $table->boolean('is_correct')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('explanation')->nullable();

            $table->timestamps();

            $table->index(['academy_id', 'question_id', 'sort_order']);
            $table->unique(['question_id', 'option_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_options');
    }
};
