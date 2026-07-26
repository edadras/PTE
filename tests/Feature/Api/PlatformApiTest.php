<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * The platform tier is super-admin-only (docs/08 §1, docs/02 §1).
 */
final class PlatformApiTest extends ApiTestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_anonymous_caller_is_rejected(): void
    {
        $this->getJson('/api/platform/v1/academies')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    #[Test]
    public function an_ordinary_user_is_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_super_admin' => false]));

        $this->getJson('/api/platform/v1/academies')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    #[Test]
    public function a_super_admin_sees_every_academy(): void
    {
        $this->academy('alpha');
        $this->academy('beta');

        Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));

        $this->getJson('/api/platform/v1/academies')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonStructure(['data' => [['id', 'slug', 'status']], 'meta' => ['request_id']]);
    }

    #[Test]
    public function a_super_admin_can_create_an_academy(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));

        $this->postJson('/api/platform/v1/academies', [
            'name' => 'Gamma Academy',
            'slug' => 'gamma',
            'owner_email' => 'owner@example.test',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.slug', 'gamma');

        $this->assertDatabaseHas('academies', ['slug' => 'gamma']);
    }

    #[Test]
    public function the_cross_tenant_analytics_endpoint_answers(): void
    {
        $this->academy('alpha');

        Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));

        $this->getJson('/api/platform/v1/analytics')
            ->assertOk()
            ->assertJsonStructure(['data' => ['academies', 'students', 'activity', 'period']]);
    }

    #[Test]
    public function the_bot_health_report_never_exposes_a_token(): void
    {
        $this->academy('alpha');

        Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));

        $response = $this->getJson('/api/platform/v1/bots/health')->assertOk();

        $this->assertStringNotContainsString('token', strtolower((string) $response->getContent()));
    }
}
