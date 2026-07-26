<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Actions\SyncPermissionCatalog;
use Illuminate\Database\Seeder;

/**
 * The permission list lives in code (PermissionCatalog) and is mirrored into
 * the table here. Code is the source of truth so a new permission ships with
 * the feature that needs it rather than as a forgotten manual insert.
 */
final class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $count = app(SyncPermissionCatalog::class)->handle();

        $this->command?->info("Synced {$count} permissions.");
    }
}
