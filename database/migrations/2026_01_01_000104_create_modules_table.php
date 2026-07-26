<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-level registry of installable capability packs (PTE, IELTS, …).
 * Not tenant-scoped: the catalogue is the same for every academy.
 *
 * @see docs/07-database-schema.md §2
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table): void {
            $table->string('key', 64)->primary();

            $table->string('name', 100);
            $table->string('version', 20)->default('1.0.0');
            $table->text('description')->nullable();
            $table->string('icon', 16)->nullable();

            $table->string('requires_plan', 40)->nullable();
            $table->json('question_types')->nullable();
            $table->json('config_schema')->nullable();
            $table->boolean('is_beta')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
