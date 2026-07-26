<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domain\Audit\Concerns\LogsActivity;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Audit\OpsServiceProvider;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LogsActivityTraitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(OpsServiceProvider::class);

        TenantContext::set(Academy::factory()->configured()->create());
    }

    #[Test]
    public function creating_updating_and_deleting_each_leave_a_trail(): void
    {
        $model = AuditedRecord::query()->create([
            'subject' => 'Original subject',
            'status' => 'open',
            'priority' => 'normal',
            'source' => 'panel',
        ]);

        $created = ActivityLog::query()->forAction(AuditAction::ModelCreated->value)->first();
        $this->assertNotNull($created);
        $this->assertSame('Original subject', $created->new_values['subject']);
        // academy_id is implied by the row itself and is never worth a column.
        $this->assertArrayNotHasKey('academy_id', $created->new_values);

        $model->update(['subject' => 'Corrected subject']);

        $updated = ActivityLog::query()->forAction(AuditAction::ModelUpdated->value)->first();
        $this->assertNotNull($updated);
        $this->assertSame('Original subject', $updated->old_values['subject']);
        $this->assertSame('Corrected subject', $updated->new_values['subject']);
        $this->assertArrayNotHasKey('status', $updated->new_values, 'Only what changed is recorded.');

        $model->delete();

        $this->assertTrue(ActivityLog::query()->forAction(AuditAction::ModelDeleted->value)->exists());
    }

    #[Test]
    public function an_excluded_column_never_reaches_the_trail(): void
    {
        $model = AuditedRecord::query()->create([
            'subject' => 'Subject',
            'status' => 'open',
            'priority' => 'normal',
            'source' => 'panel',
        ]);

        ActivityLog::pruneOlderThan(now()->addMinute());

        $model->update(['last_reply_at' => now()]);

        $this->assertSame(
            0,
            ActivityLog::query()->forAction(AuditAction::ModelUpdated->value)->count(),
            'A change confined to excluded columns is not worth an audit row.'
        );
    }
}

/**
 * A stand-in for a configuration-shaped model. It borrows the support_tickets
 * table so the trait can be exercised without inventing a migration.
 */
class AuditedRecord extends Model
{
    use BelongsToAcademy;
    use LogsActivity;

    public $timestamps = true;

    protected $table = 'support_tickets';

    protected $guarded = ['id'];

    /** @var array<int, string> */
    protected array $auditExcept = ['last_reply_at'];
}
