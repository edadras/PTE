<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesAcademy;
use App\Domain\Telegram\Jobs\CheckBotHealth;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Services\BotManager;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Inspect bot health, either as a report (`--all`) or for one academy.
 *
 * `--sync` runs the check inline and prints the result, which is what an
 * operator following runbook #1 actually wants; without it the checks are
 * queued the same way the five-minute scheduler does it.
 *
 * @see docs/10-infrastructure-and-ops.md §8 · docs/04-telegram-layer.md §10
 */
final class TelegramHealthCheckCommand extends Command
{
    use ResolvesAcademy;

    protected $signature = 'telegram:health-check
        {--all : Check every active bot on the platform}
        {--academy= : Check one academy (id or slug)}
        {--sync : Run the checks inline and print the outcome}';

    protected $description = 'Check Telegram webhook health for one academy or the whole platform.';

    public function handle(BotManager $manager): int
    {
        $academyOption = $this->option('academy');
        $all = $this->option('all') === true;

        if (! $all && ! is_string($academyOption)) {
            $this->components->error(__('reports.console.health_needs_target'));

            return self::INVALID;
        }

        $bots = $this->targets(is_string($academyOption) ? $academyOption : null);

        if ($bots === []) {
            $this->components->warn(__('reports.console.no_active_bots'));

            return self::SUCCESS;
        }

        if ($this->option('sync') !== true) {
            foreach ($bots as [$academy, $bot]) {
                CheckBotHealth::dispatch((int) $academy->getKey(), (int) $bot->getKey());
            }

            $this->components->info(__('reports.console.health_queued', ['count' => (string) count($bots)]));

            return self::SUCCESS;
        }

        $rows = [];
        $failing = 0;

        foreach ($bots as [$academy, $bot]) {
            $status = TenantContext::runFor($academy, function () use ($manager, $bot): string {
                try {
                    return $manager->checkHealth($bot)->value;
                } catch (Throwable $e) {
                    return 'error: '.$e->getMessage();
                }
            });

            if ($status !== 'ok') {
                $failing++;
            }

            $rows[] = [
                $academy->slug,
                $bot->username ?? $bot->public_id,
                $status,
                $bot->pending_update_count,
                $bot->last_error === null ? '—' : mb_substr((string) $bot->last_error, 0, 60),
            ];
        }

        $this->table(['Academy', 'Bot', 'Health', 'Pending', 'Last error'], $rows);

        return $failing > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<int, array{0: Academy, 1: TelegramBot}>
     */
    private function targets(?string $academyReference): array
    {
        $academies = $academyReference === null
            ? Academy::query()->withoutGlobalScopes()->whereNull('deleted_at')->get()->keyBy(fn (Academy $a): int => (int) $a->getKey())
            : collect(array_filter([$this->requireAcademy($academyReference, withTrashed: false)]))
                ->keyBy(fn (Academy $a): int => (int) $a->getKey());

        if ($academies->isEmpty()) {
            return [];
        }

        $bots = TelegramBot::query()
            ->withoutGlobalScope('academy')
            ->where('is_active', true)
            ->whereIn('academy_id', $academies->keys()->all())
            ->orderBy('academy_id')
            ->get();

        $targets = [];

        foreach ($bots as $bot) {
            $academy = $academies->get((int) $bot->academy_id);

            if ($academy instanceof Academy) {
                $targets[] = [$academy, $bot];
            }
        }

        return $targets;
    }
}
