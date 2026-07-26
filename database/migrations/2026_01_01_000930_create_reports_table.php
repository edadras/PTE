<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generated exports and report cards. The file itself lives on the tenant disk;
 * this row is the handle, the expiry and the audit anchor for who asked for it.
 *
 * @see docs/07-database-schema.md §10 · docs/10-infrastructure-and-ops.md §4
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            $table->string('type', 48);
            $table->string('format', 8)->default('csv');
            $table->json('params')->nullable();

            $table->string('file_path', 255)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('row_count')->nullable();

            $table->string('status', 16)->default('pending');
            $table->text('error')->nullable();

            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['academy_id', 'status', 'created_at']);
            $table->index(['academy_id', 'type', 'created_at']);
            $table->index(['academy_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
