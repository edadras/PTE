<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a student came from: deep link, campaign or another student's referral.
 *
 * @see docs/07-database-schema.md §4
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_acquisitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();

            $table->string('source', 40)->default('telegram');
            $table->string('campaign_id', 64)->nullable();
            $table->foreignId('referrer_student_id')->nullable()->constrained('students')->nullOnDelete();

            $table->json('payload')->nullable();

            $table->timestamps();

            $table->index(['academy_id', 'source']);
            $table->index(['academy_id', 'campaign_id']);
            $table->index(['academy_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_acquisitions');
    }
};
