<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalised per-session summary. Recomputing a report card from `answers`
 * every time a dashboard loads is the query that kills the database first, so
 * the aggregate is written once when the session closes.
 *
 * @see docs/07-database-schema.md §7
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            $table->unsignedBigInteger('student_id');

            $table->string('session_type', 16);
            $table->unsignedBigInteger('session_id');

            $table->string('module_key', 40)->nullable();

            $table->decimal('raw_score', 8, 2)->default(0);
            $table->decimal('scaled_score', 8, 2)->nullable();
            $table->decimal('percentage', 5, 2)->default(0);

            $table->json('breakdown')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['academy_id', 'session_type', 'session_id']);
            $table->index(['academy_id', 'student_id', 'created_at']);
            $table->index(['academy_id', 'module_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scores');
    }
};
