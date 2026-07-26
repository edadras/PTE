<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @see docs/07-database-schema.md §7 · docs/05-modules-exams-practice.md §5
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();

            $table->string('title', 190);
            $table->string('module_key', 40);

            $table->unsignedSmallInteger('duration_minutes')->default(0);
            $table->decimal('score', 8, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->string('selection_mode', 16)->default('manual');

            // selection_config shape depends on selection_mode:
            //   random → {types:[], difficulty:'medium', bank_id:1, count:5}
            //   pool   → {take:10}   (candidates live in exam_questions)
            $table->json('selection_config')->nullable();

            $table->timestamps();

            $table->index(['academy_id', 'exam_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_sections');
    }
};
