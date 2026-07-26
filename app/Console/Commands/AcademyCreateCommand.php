<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Commerce\Models\Plan;
use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Tenancy\Actions\CreateAcademy;
use App\Domain\Tenancy\Data\CreateAcademyData;
use Illuminate\Console\Command;
use Throwable;

/**
 * @see docs/10-infrastructure-and-ops.md §8
 */
final class AcademyCreateCommand extends Command
{
    protected $signature = 'academy:create
        {name : Display name of the academy}
        {owner-email : Email of the owner; the user is created if absent}
        {--slug= : Subdomain slug (defaults to a slug of the name)}
        {--plan= : Plan key or id to start on}
        {--locale=fa}
        {--timezone=Asia/Tehran}
        {--currency=IRR}
        {--modules=* : Module keys to enable}';

    protected $description = 'Create a new academy with its owner, brand, settings and default subdomain.';

    public function handle(CreateAcademy $createAcademy, AuditRecorder $audit): int
    {
        $name = (string) $this->argument('name');
        $email = (string) $this->argument('owner-email');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->components->error(__('reports.console.invalid_email', ['email' => $email]));

            return self::INVALID;
        }

        $plan = $this->resolvePlan();

        if ($plan === false) {
            return self::INVALID;
        }

        try {
            $academy = $createAcademy->handle(new CreateAcademyData(
                name: $name,
                slug: $this->stringOption('slug'),
                ownerEmail: $email,
                locale: (string) $this->option('locale'),
                currency: (string) $this->option('currency'),
                timezone: (string) $this->option('timezone'),
                planId: $plan,
                modules: $this->modules(),
            ));
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $audit->recordPlatform(AuditAction::AcademyCreated, $academy, [
            'slug' => $academy->slug,
            'owner_email' => $email,
            'plan_id' => $plan,
        ]);

        $this->components->info(__('reports.console.academy_created', [
            'name' => $academy->name,
            'id' => (string) $academy->getKey(),
        ]));

        $this->table(
            ['id', 'slug', 'status', 'url'],
            [[$academy->getKey(), $academy->slug, $academy->status->value, $academy->url()]],
        );

        return self::SUCCESS;
    }

    /** @return int|null|false false signals "the operator asked for a plan that does not exist". */
    private function resolvePlan(): int|null|false
    {
        $plan = $this->stringOption('plan');

        if ($plan === null) {
            return null;
        }

        $model = Plan::query()
            ->when(is_numeric($plan), fn ($q) => $q->orWhere('id', (int) $plan))
            ->orWhere('key', $plan)
            ->first();

        if ($model === null) {
            $this->components->error(__('reports.console.plan_not_found', ['plan' => $plan]));

            return false;
        }

        return (int) $model->getKey();
    }

    /**
     * @return array<int, string>
     */
    private function modules(): array
    {
        /** @var array<int, string> $modules */
        $modules = (array) $this->option('modules');

        if ($modules === []) {
            return [
                ModuleKey::PteSpeaking->value,
                ModuleKey::PteListening->value,
                ModuleKey::PteReading->value,
                ModuleKey::PteWriting->value,
            ];
        }

        return array_values(array_filter($modules, static fn (string $key): bool => ModuleKey::tryFrom($key) !== null));
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
