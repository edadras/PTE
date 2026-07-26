<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant root. Every tenant-scoped table in the platform hangs off this one.
 *
 * @see docs/07-database-schema.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academies', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name', 150);
            $table->string('legal_name', 190)->nullable();
            $table->string('status', 20)->default('active')->index();

            $table->foreignId('plan_id')->nullable()->index();
            $table->timestamp('trial_ends_at')->nullable();

            $table->string('timezone', 64)->default('UTC');
            $table->string('country', 2)->nullable();

            // Enterprise escape hatch — null means "shared connection".
            $table->string('database_connection', 64)->nullable();

            $table->foreignId('owner_user_id')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academies');
    }
};
