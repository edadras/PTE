<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant overrides of platform message copy.
 *
 * A row only exists once an academy customises the text; anything else falls
 * back to the platform default in the language files, so improvements to the
 * defaults reach every academy that never edited them.
 *
 * @see docs/03-white-label.md §6
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();

            $table->string('key', 64);
            $table->string('locale', 5)->default('fa');
            $table->string('channel', 20)->default('telegram');

            $table->text('content')->nullable();
            $table->boolean('is_customized')->default(false);

            $table->timestamps();

            $table->unique(['academy_id', 'key', 'locale', 'channel'], 'message_templates_tenant_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
