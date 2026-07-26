<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Data\CreateAcademyData;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\Models\AcademyBrand;
use App\Domain\Tenancy\Models\AcademyModule;
use App\Domain\Tenancy\Models\AcademySettings;
use App\Domain\Tenancy\Models\MessageTemplate;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Fast onboarding: stand up a new academy shaped like an existing one.
 *
 * This action clones what the Tenancy context owns — brand, settings, enabled
 * modules and customised copy. Menus, flows, prompts, rubrics and the question
 * bank are cloned by their own contexts, which know their invariants.
 *
 * @see docs/01-multi-tenancy.md §8
 */
final class CloneAcademy
{
    /** Columns that must never be carried over to the clone. */
    private const NEVER_COPY = ['id', 'academy_id', 'created_at', 'updated_at', 'deleted_at'];

    public function __construct(private readonly CreateAcademy $createAcademy) {}

    public function handle(Academy $source, CreateAcademyData $data): Academy
    {
        return DB::transaction(function () use ($source, $data): Academy {
            $target = $this->createAcademy->handle($data);

            TenantContext::runFor($target, function () use ($source, $target): void {
                $this->copyBrand($source, $target);
                $this->copySettings($source, $target);
                $this->copyModules($source, $target);
                $this->copyMessageTemplates($source, $target);
            });

            return $target->refresh();
        });
    }

    private function copyBrand(Academy $source, Academy $target): void
    {
        $sourceBrand = $source->brand;

        if (! $sourceBrand instanceof AcademyBrand) {
            return;
        }

        $attributes = $this->copyable($sourceBrand);

        // Uploaded assets belong to the source tenant's storage folder.
        unset(
            $attributes['logo_light_path'],
            $attributes['logo_dark_path'],
            $attributes['icon_path'],
            $attributes['welcome_image_path'],
        );

        $attributes['display_name'] = $target->name;
        $attributes['short_name'] = $target->name;

        AcademyBrand::query()
            ->where('academy_id', $target->getKey())
            ->update($attributes);
    }

    private function copySettings(Academy $source, Academy $target): void
    {
        $sourceSettings = $source->settings;

        if (! $sourceSettings instanceof AcademySettings) {
            return;
        }

        AcademySettings::query()
            ->where('academy_id', $target->getKey())
            ->update($this->copyable($sourceSettings));
    }

    private function copyModules(Academy $source, Academy $target): void
    {
        foreach ($source->modules as $module) {
            AcademyModule::query()->updateOrCreate(
                ['academy_id' => $target->getKey(), 'module_key' => $module->module_key],
                [
                    'is_enabled' => $module->is_enabled,
                    'settings' => $module->settings,
                    'enabled_at' => $module->is_enabled ? now() : null,
                ]
            );
        }
    }

    private function copyMessageTemplates(Academy $source, Academy $target): void
    {
        $templates = MessageTemplate::query()
            ->withoutGlobalScope('academy')
            ->where('academy_id', $source->getKey())
            ->customized()
            ->get();

        foreach ($templates as $template) {
            MessageTemplate::query()->updateOrCreate(
                [
                    'academy_id' => $target->getKey(),
                    'key' => $template->key,
                    'locale' => $template->locale,
                    'channel' => $template->channel,
                ],
                [
                    'content' => $template->content,
                    'is_customized' => true,
                ]
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function copyable(Model $model): array
    {
        return array_diff_key($model->getAttributes(), array_flip(self::NEVER_COPY));
    }
}
