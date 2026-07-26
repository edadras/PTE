<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesAcademy;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Throwable;

/**
 * Run any Artisan command inside a tenant.
 *
 * The escape hatch that makes every other command tenant-agnostic: rather than
 * teaching `db:seed`, `queue:retry` or a bespoke fix-up command about academies,
 * they are run *inside* one and the global scope does the rest.
 *
 * Always audited (both platform-wide and in the academy's own trail): an
 * operator running arbitrary code against a customer's data with the tenant
 * scope pre-satisfied is the single most powerful thing this CLI can do.
 *
 *   php artisan tenant:run acme-academy telegram:health-check --academy=acme-academy
 *   php artisan tenant:run 4 "db:seed --class=SampleQuestionBankSeeder"
 *
 * @see docs/10-infrastructure-and-ops.md §8
 */
final class TenantRunCommand extends Command
{
    use ResolvesAcademy;

    /**
     * The inner command is variadic so both quoted ("a --b=c") and unquoted
     * (a --b=c) forms work — an operator should not have to remember which.
     */
    protected $signature = 'tenant:run {academy : Academy id or slug} {cmd* : The artisan command and its arguments}';

    protected $description = 'Run an artisan command inside a tenant context.';

    public function handle(Kernel $kernel, AuditRecorder $audit): int
    {
        $academy = $this->requireAcademy($this->argument('academy'), withTrashed: false);

        if ($academy === null) {
            return self::FAILURE;
        }

        /** @var array<int, string> $parts */
        $parts = (array) $this->argument('cmd');
        [$command, $parameters] = $this->parse($parts);

        if ($command === null) {
            $this->components->error(__('reports.console.missing_command'));

            return self::INVALID;
        }

        if ($this->isForbidden($command)) {
            $this->components->error(__('reports.console.forbidden_command', ['command' => $command]));

            return self::INVALID;
        }

        $audit->recordPlatform(AuditAction::TenantCommandRun, $academy, [
            'command' => $command,
            'parameters' => $parameters,
        ]);

        $this->components->info(__('reports.console.running_in_tenant', [
            'command' => $command,
            'academy' => (string) $academy->slug,
        ]));

        try {
            return TenantContext::runFor($academy, fn (): int => $this->runInside($kernel, $academy, $command, $parameters));
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function runInside(Kernel $kernel, Academy $academy, string $command, array $parameters): int
    {
        // Sanity check rather than ceremony: if this ever fails, every query the
        // inner command runs would silently address the wrong tenant.
        if (TenantContext::idOrNull() !== (int) $academy->getKey()) {
            $this->components->error(__('reports.console.tenant_not_set'));

            return self::FAILURE;
        }

        return $kernel->call($command, $parameters, $this->output);
    }

    /**
     * Split the variadic argument into a command name and its parameters.
     *
     * @param  array<int, string>  $parts
     * @return array{0: string|null, 1: array<string, mixed>}
     */
    private function parse(array $parts): array
    {
        // A single quoted string is the documented form; expand it back out.
        if (count($parts) === 1 && str_contains($parts[0], ' ')) {
            $split = preg_split('/\s+/', trim($parts[0]));
            $parts = $split === false ? $parts : $split;
        }

        $command = array_shift($parts);

        if ($command === null || $command === '') {
            return [null, []];
        }

        $parameters = [];
        $positional = 0;

        foreach ($parts as $part) {
            if (! str_starts_with($part, '--')) {
                $parameters[$positional++] = $part;

                continue;
            }

            $option = substr($part, 2);

            if (str_contains($option, '=')) {
                [$name, $value] = explode('=', $option, 2);
                $parameters['--'.$name] = $value;

                continue;
            }

            $parameters['--'.$option] = true;
        }

        return [$command, $parameters];
    }

    /**
     * Commands that must never be run "inside a tenant".
     *
     * `migrate:fresh` and friends would drop every academy's data while wearing
     * one academy's context, which reads in the log like a scoped operation and
     * is anything but.
     */
    private function isForbidden(string $command): bool
    {
        return in_array($command, [
            'migrate:fresh',
            'migrate:reset',
            'migrate:rollback',
            'db:wipe',
            'tenant:run',
        ], true);
    }
}
