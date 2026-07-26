<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Class groups are what bounds a teacher's reach inside an academy.
 *
 * `course_id` carries no foreign key: `courses` belongs to the Learning
 * context and is created in a later migration batch (0005xx).
 *
 * @see docs/07-database-schema.md §4
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            $table->string('name', 120);
            $table->unsignedBigInteger('course_id')->nullable();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->string('status', 20)->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['academy_id', 'status']);
            $table->index(['academy_id', 'teacher_id']);
            $table->index(['academy_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_groups');
    }
};
