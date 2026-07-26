<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Learning\Support\ModuleRegistry;
use App\Domain\Tenancy\Models\Module;
use Illuminate\Database\Seeder;

/**
 * Mirrors ModuleRegistry into the `modules` table.
 *
 * The registry is the source of truth — the table exists so that
 * academy_modules can hold a foreign key and so the catalogue is queryable,
 * not so it can drift from the code that actually implements each module.
 */
final class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ModuleRegistry::catalogue() as $definition) {
            Module::query()->updateOrCreate(
                ['key' => $definition['key']],
                [
                    'name' => $definition['name'],
                    'icon' => $definition['icon'],
                    'requires_plan' => $definition['requires_plan'],
                    'question_types' => $definition['question_types'],
                    'is_beta' => $definition['is_beta'],
                ]
            );
        }

        $this->command?->info('Synced '.count(ModuleRegistry::catalogue()).' modules.');
    }
}
