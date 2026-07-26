<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `users` stays global (no academy_id) — a person is one identity across every
 * academy they work for. Membership lives in `academy_user_roles`.
 *
 * `is_super_admin` is deliberately a column and not a role: keeping it outside
 * the tenant-scoped role system means a bug in tenant scoping can never turn
 * into privilege escalation.
 *
 * @see docs/02-roles-and-rbac.md §1
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 32)->nullable()->after('email');
            $table->boolean('is_super_admin')->default(false)->after('password');
            $table->string('locale', 5)->default('fa')->after('is_super_admin');
            $table->string('avatar_path')->nullable()->after('locale');

            $table->text('two_factor_secret')->nullable()->after('avatar_path');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');

            $table->timestamp('last_login_at')->nullable()->after('two_factor_confirmed_at');

            $table->softDeletes();

            $table->index('is_super_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['is_super_admin']);

            $table->dropColumn([
                'phone',
                'is_super_admin',
                'locale',
                'avatar_path',
                'two_factor_secret',
                'two_factor_confirmed_at',
                'last_login_at',
                'deleted_at',
            ]);
        });
    }
};
