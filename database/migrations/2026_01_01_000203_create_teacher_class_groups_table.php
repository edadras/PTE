<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The teacher's second scope: on top of `academy_id`, a teacher only reaches
 * the class groups assigned here.
 *
 * `class_group_id` carries no foreign key because `class_groups` is created in
 * the students migration batch (0003xx), which runs after this one.
 *
 * @see docs/02-roles-and-rbac.md §2 (Teacher)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_class_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('class_group_id');

            $table->timestamps();

            $table->unique(['academy_id', 'user_id', 'class_group_id'], 'teacher_class_groups_unique');
            $table->index(['academy_id', 'class_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_class_groups');
    }
};
