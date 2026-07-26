<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @see docs/07-database-schema.md §4
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_group_students', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();
            $table->foreignId('class_group_id')->constrained('class_groups')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();

            $table->timestamp('enrolled_at')->nullable();

            $table->timestamps();

            $table->unique(['academy_id', 'class_group_id', 'student_id'], 'class_group_students_unique');
            $table->index(['academy_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_group_students');
    }
};
