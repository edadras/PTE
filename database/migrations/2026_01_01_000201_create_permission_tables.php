<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spatie/laravel-permission tables, published and adapted:
 *
 *  - team mode is on and the team key is `academy_id` (a role only means
 *    something inside one academy; `academy_id = null` marks a platform role);
 *  - `roles` and `permissions` carry the presentation/grouping columns from
 *    docs/07 so the panel never has to hard-code labels.
 *
 * @see docs/02-roles-and-rbac.md §6 · docs/07-database-schema.md §3
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $teamKey = $columnNames['team_foreign_key'] ?? 'academy_id';
        $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';
        $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';

        throw_if(empty($tableNames), Exception::class, 'config/permission.php is not loaded. Run [php artisan config:clear] and try again.');

        Schema::create($tableNames['permissions'], function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name', 191);
            $table->string('guard_name', 40)->default('web');

            $table->string('group', 60)->nullable();
            $table->string('display_name', 120)->nullable();
            $table->string('description')->nullable();
            $table->string('scope', 20)->default('academy');

            $table->timestamps();

            $table->unique(['name', 'guard_name']);
            $table->index('group');
            $table->index('scope');
        });

        Schema::create($tableNames['roles'], function (Blueprint $table) use ($teamKey): void {
            $table->bigIncrements('id');

            // Nullable: platform-level roles have no academy.
            $table->unsignedBigInteger($teamKey)->nullable();
            $table->index($teamKey, 'roles_team_foreign_key_index');

            $table->string('name', 120);
            $table->string('guard_name', 40)->default('web');

            $table->string('display_name', 120)->nullable();
            $table->string('color', 20)->nullable();
            $table->boolean('is_system')->default(false);
            $table->unsignedTinyInteger('level')->default(0);

            $table->timestamps();

            $table->unique([$teamKey, 'name', 'guard_name']);
        });

        Schema::create($tableNames['model_has_permissions'], function (Blueprint $table) use ($tableNames, $columnNames, $pivotPermission, $teamKey): void {
            $table->unsignedBigInteger($pivotPermission);

            $table->string('model_type', 191);
            $table->unsignedBigInteger($columnNames['model_morph_key']);
            $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_permissions_model_id_model_type_index');

            $table->foreign($pivotPermission)
                ->references('id')
                ->on($tableNames['permissions'])
                ->cascadeOnDelete();

            $table->unsignedBigInteger($teamKey);
            $table->index($teamKey, 'model_has_permissions_team_foreign_key_index');

            $table->primary(
                [$teamKey, $pivotPermission, $columnNames['model_morph_key'], 'model_type'],
                'model_has_permissions_permission_model_type_primary'
            );
        });

        Schema::create($tableNames['model_has_roles'], function (Blueprint $table) use ($tableNames, $columnNames, $pivotRole, $teamKey): void {
            $table->unsignedBigInteger($pivotRole);

            $table->string('model_type', 191);
            $table->unsignedBigInteger($columnNames['model_morph_key']);
            $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_roles_model_id_model_type_index');

            $table->foreign($pivotRole)
                ->references('id')
                ->on($tableNames['roles'])
                ->cascadeOnDelete();

            $table->unsignedBigInteger($teamKey);
            $table->index($teamKey, 'model_has_roles_team_foreign_key_index');

            $table->primary(
                [$teamKey, $pivotRole, $columnNames['model_morph_key'], 'model_type'],
                'model_has_roles_role_model_type_primary'
            );
        });

        Schema::create($tableNames['role_has_permissions'], function (Blueprint $table) use ($tableNames, $pivotRole, $pivotPermission): void {
            $table->unsignedBigInteger($pivotPermission);
            $table->unsignedBigInteger($pivotRole);

            $table->foreign($pivotPermission)
                ->references('id')
                ->on($tableNames['permissions'])
                ->cascadeOnDelete();

            $table->foreign($pivotRole)
                ->references('id')
                ->on($tableNames['roles'])
                ->cascadeOnDelete();

            $table->primary([$pivotPermission, $pivotRole], 'role_has_permissions_permission_id_role_id_primary');
        });

        app('cache')
            ->store(config('permission.cache.store') !== 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        $tableNames = config('permission.table_names');

        throw_if(empty($tableNames), Exception::class, 'config/permission.php is not loaded. Run [php artisan config:clear] and try again.');

        Schema::dropIfExists($tableNames['role_has_permissions']);
        Schema::dropIfExists($tableNames['model_has_roles']);
        Schema::dropIfExists($tableNames['model_has_permissions']);
        Schema::dropIfExists($tableNames['roles']);
        Schema::dropIfExists($tableNames['permissions']);
    }
};
