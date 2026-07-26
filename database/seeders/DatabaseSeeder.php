<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Platform-level reference data only.
 *
 * Nothing here is tenant-scoped: plans, AI models, permissions, modules and the
 * default prompt library are the catalogue every academy is provisioned from.
 * Per-academy seeding happens inside CreateAcademy.
 */
final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            ModuleSeeder::class,
            PlanSeeder::class,
            AiModelSeeder::class,
            DefaultPromptSeeder::class,
        ]);
    }
}
