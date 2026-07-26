<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\PlatformAuditLog;
use App\Domain\Audit\OpsServiceProvider;
use App\Domain\Identity\Models\Student;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TenantRunCommandTest extends TestCase
{
    use RefreshDatabase;

    private Academy $alpha;

    private Academy $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(OpsServiceProvider::class);

        $this->alpha = Academy::factory()->configured()->create(['slug' => 'alpha']);
        $this->beta = Academy::factory()->configured()->create(['slug' => 'beta']);

        TenantContext::runFor($this->alpha, fn () => Student::factory()->count(3)->create());
        TenantContext::runFor($this->beta, fn () => Student::factory()->count(7)->create());

        $this->app->make(Kernel::class)->registerCommand(new ProbeTenantCommand);
    }

    #[Test]
    public function the_inner_command_sees_exactly_the_named_tenant(): void
    {
        $this->artisan('tenant:run', ['academy' => 'beta', 'cmd' => ['tenant:probe']])
            ->expectsOutputToContain('academy='.$this->beta->getKey())
            ->expectsOutputToContain('students=7')
            ->assertSuccessful();
    }

    #[Test]
    public function it_accepts_an_id_as_readily_as_a_slug(): void
    {
        $this->artisan('tenant:run', ['academy' => (string) $this->alpha->getKey(), 'cmd' => ['tenant:probe']])
            ->expectsOutputToContain('students=3')
            ->assertSuccessful();
    }

    #[Test]
    public function options_survive_the_hand_off_in_both_quoted_and_unquoted_form(): void
    {
        $this->artisan('tenant:run', ['academy' => 'beta', 'cmd' => ['tenant:probe', '--label=split']])
            ->expectsOutputToContain('label=split')
            ->assertSuccessful();

        $this->artisan('tenant:run', ['academy' => 'beta', 'cmd' => ['tenant:probe --label=quoted']])
            ->expectsOutputToContain('label=quoted')
            ->assertSuccessful();
    }

    #[Test]
    public function the_tenant_is_released_again_afterwards(): void
    {
        $this->artisan('tenant:run', ['academy' => 'beta', 'cmd' => ['tenant:probe']])->assertSuccessful();

        $this->assertNull(TenantContext::idOrNull());
    }

    #[Test]
    public function destructive_commands_are_refused(): void
    {
        $this->artisan('tenant:run', ['academy' => 'beta', 'cmd' => ['migrate:fresh']])
            ->assertExitCode(Command::INVALID);
    }

    #[Test]
    public function running_a_command_inside_a_tenant_is_audited(): void
    {
        $this->artisan('tenant:run', ['academy' => 'beta', 'cmd' => ['tenant:probe']])->assertSuccessful();

        $entry = PlatformAuditLog::query()
            ->where('action', AuditAction::TenantCommandRun->value)
            ->forAcademy($this->beta)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame('tenant:probe', $entry->payload['command']);
    }

    #[Test]
    public function an_unknown_academy_fails_cleanly(): void
    {
        $this->artisan('tenant:run', ['academy' => 'ghost', 'cmd' => ['tenant:probe']])->assertFailed();
    }
}

/**
 * A stand-in for whatever the operator actually runs; it reports which tenant it
 * was executed in, which is the whole contract under test.
 */
final class ProbeTenantCommand extends Command
{
    protected $signature = 'tenant:probe {--label=none}';

    protected $description = 'Report the tenant this command is executing inside.';

    public function handle(): int
    {
        $this->line('academy='.TenantContext::idOrNull());
        $this->line('students='.Student::query()->count());
        $this->line('label='.$this->option('label'));

        return self::SUCCESS;
    }
}
