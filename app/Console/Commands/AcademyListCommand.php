<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Identity\Models\Student;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Tenancy\Models\Academy;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * @see docs/10-infrastructure-and-ops.md §8
 */
final class AcademyListCommand extends Command
{
    protected $signature = 'academy:list
        {--status= : Filter by status (active|suspended|deleted)}
        {--trashed : Include soft-deleted academies}
        {--json : Emit JSON instead of a table}';

    protected $description = 'List academies with their status, plan, bot health and student count.';

    public function handle(): int
    {
        $academies = Academy::query()
            ->withoutGlobalScopes()
            ->when($this->option('trashed') !== true, fn (Builder $q): Builder => $q->whereNull('deleted_at'))
            ->when(
                is_string($this->option('status')) && $this->option('status') !== '',
                fn (Builder $q): Builder => $q->where('status', (string) $this->option('status'))
            )
            ->orderBy('id')
            ->get(['id', 'slug', 'name', 'status', 'plan_id', 'timezone', 'created_at', 'deleted_at']);

        // Two grouped queries instead of one per academy: this command is run
        // against production, where N+1 over a hundred tenants is noticeable.
        $students = Student::query()
            ->withoutGlobalScope('academy')
            ->whereNull('deleted_at')
            ->selectRaw('academy_id, COUNT(*) as total')
            ->groupBy('academy_id')
            ->pluck('total', 'academy_id');

        // toBase() keeps the enum cast out of the way: this is a display string,
        // and casting it back would only mean unwrapping it again below.
        $bots = TelegramBot::query()
            ->withoutGlobalScope('academy')
            ->where('is_active', true)
            ->toBase()
            ->pluck('health_status', 'academy_id');

        $rows = $academies->map(static fn (Academy $academy): array => [
            'id' => (int) $academy->getKey(),
            'slug' => (string) $academy->slug,
            'name' => (string) $academy->name,
            'status' => $academy->status->value,
            'plan_id' => (string) ($academy->plan_id ?? '—'),
            'timezone' => (string) ($academy->timezone ?? '—'),
            'students' => (int) ($students[$academy->getKey()] ?? 0),
            'bot' => (string) ($bots[$academy->getKey()] ?? '—'),
            'created_at' => $academy->created_at?->toDateString(),
        ])->all();

        if ($this->option('json') === true) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->components->warn(__('reports.console.no_academies'));

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Slug', 'Name', 'Status', 'Plan', 'Timezone', 'Students', 'Bot', 'Created'],
            array_map(array_values(...), $rows),
        );

        return self::SUCCESS;
    }
}
