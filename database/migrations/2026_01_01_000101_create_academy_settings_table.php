<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational configuration of a tenant — everything that is not visual.
 *
 * @see docs/07-database-schema.md §2
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academy_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->unique()->constrained('academies')->cascadeOnDelete();

            $table->string('locale', 5)->default('fa');
            $table->string('currency', 3)->default('IRR');

            $table->json('practice_config')->nullable();
            $table->json('exam_config')->nullable();
            $table->json('notification_config')->nullable();
            $table->json('features')->nullable();

            $table->unsignedSmallInteger('data_retention_days')->default(365);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academy_settings');
    }
};
