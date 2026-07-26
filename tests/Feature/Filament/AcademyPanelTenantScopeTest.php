<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Student;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Academy\Resources\StudentResource\Pages\ListStudents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * The panel inherits tenant scoping from BelongsToAcademy — no resource adds a
 * `where academy_id` of its own, so this test is what proves the inheritance
 * actually happens.
 */
final class AcademyPanelTenantScopeTest extends FilamentTestCase
{
    use RefreshDatabase;

    private Academy $alpha;

    private Academy $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->makeAcademy('alpha');
        $this->beta = $this->makeAcademy('beta');

        TenantContext::runFor($this->alpha, function (): void {
            Student::factory()->count(3)->create(['first_name' => 'Alpha']);
        });

        TenantContext::runFor($this->beta, function (): void {
            Student::factory()->count(5)->create(['first_name' => 'Beta']);
        });

        $this->forgetHostCache($this->alpha);
        $this->forgetHostCache($this->beta);
    }

    #[Test]
    public function the_student_list_shows_only_the_resolved_tenants_students(): void
    {
        $owner = $this->makeStaff($this->alpha, SystemRole::Owner);

        $this->actingAs($owner)
            ->get($this->panelUrl($this->alpha, 'students'))
            ->assertSuccessful();

        TenantContext::set($this->alpha);

        $records = Livewire::test(ListStudents::class)
            ->assertSuccessful()
            ->instance()
            ->getFilteredTableQuery()
            ->get();

        $this->assertCount(3, $records);
        $this->assertTrue($records->every(
            fn (Student $student): bool => (int) $student->academy_id === (int) $this->alpha->getKey()
        ));
    }

    #[Test]
    public function staff_of_one_academy_cannot_open_another_academys_panel(): void
    {
        $owner = $this->makeStaff($this->alpha, SystemRole::Owner);

        $this->actingAs($owner)
            ->get($this->panelUrl($this->beta, 'students'))
            ->assertForbidden();
    }

    #[Test]
    public function an_unknown_hostname_is_a_404_and_never_a_redirect(): void
    {
        $this->get('http://not-a-tenant.example.test/panel')->assertNotFound();
    }
}
