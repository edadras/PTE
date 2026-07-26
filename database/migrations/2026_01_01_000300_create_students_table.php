<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Students are tenant-scoped on purpose: the same person enrolling at two
 * academies gets two rows, because progress, purchases and scores must never
 * cross the boundary.
 *
 * @see docs/01-multi-tenancy.md §5 · docs/07-database-schema.md §4
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            $table->string('student_code', 32);
            $table->string('first_name', 80);
            $table->string('last_name', 80)->nullable();

            $table->string('email', 191)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('locale', 5)->default('fa');
            $table->string('level', 10)->nullable();
            $table->decimal('target_score', 5, 2)->nullable();

            $table->string('status', 20)->default('active');
            $table->string('source', 20)->default('telegram');

            $table->string('subscription_status', 20)->nullable();
            $table->timestamp('subscription_expires_at')->nullable();

            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('registered_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['academy_id', 'student_code']);
            $table->index(['academy_id', 'status']);
            $table->index(['academy_id', 'last_active_at']);
            $table->index(['academy_id', 'phone']);
            $table->index(['academy_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
