<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff membership: which global user acts in which academy, and as what.
 *
 * This is the authoritative membership record for the panel (invitations,
 * suspension, joined_at); spatie's `model_has_roles` is the permission-check
 * projection of the same fact.
 *
 * @see docs/07-database-schema.md §3
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academy_user_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('role_id');

            $table->string('status', 20)->default('active');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();

            $table->timestamps();

            $table->unique(['academy_id', 'user_id', 'role_id']);
            $table->index(['academy_id', 'user_id']);
            $table->index(['academy_id', 'status']);
            $table->index('user_id');

            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academy_user_roles');
    }
};
