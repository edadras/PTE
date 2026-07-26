<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The white-label surface of a tenant: everything a student ever sees.
 *
 * @see docs/03-white-label.md §2
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academy_brands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_id')->unique()->constrained('academies')->cascadeOnDelete();

            $table->string('display_name', 100);
            $table->string('short_name', 40)->nullable();
            $table->string('tagline', 160)->nullable();

            $table->string('logo_light_path')->nullable();
            $table->string('logo_dark_path')->nullable();
            $table->string('icon_path')->nullable();
            $table->string('welcome_image_path')->nullable();

            $table->string('primary_color', 7)->default('#2563EB');
            $table->string('secondary_color', 7)->default('#7C3AED');
            $table->string('accent_color', 7)->nullable();
            $table->string('success_color', 7)->default('#16A34A');
            $table->string('danger_color', 7)->default('#DC2626');

            $table->string('dark_mode', 10)->default('auto');
            $table->string('font_family', 60)->default('Vazirmatn');

            $table->text('welcome_text')->nullable();
            $table->text('footer_text')->nullable();

            $table->string('website_url')->nullable();
            $table->string('instagram_url')->nullable();
            $table->string('telegram_channel')->nullable();
            $table->string('whatsapp')->nullable();

            $table->string('support_phone', 40)->nullable();
            $table->string('support_email')->nullable();
            $table->text('address')->nullable();
            $table->text('working_hours')->nullable();

            $table->string('default_locale', 5)->default('fa');
            $table->json('supported_locales')->nullable();

            $table->string('terms_url')->nullable();
            $table->string('privacy_url')->nullable();

            // Enterprise only, sanitised before it is ever rendered.
            $table->text('custom_css')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academy_brands');
    }
};
