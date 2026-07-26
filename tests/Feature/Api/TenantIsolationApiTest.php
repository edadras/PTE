<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Identity\Models\Student;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The blocking test for this layer: an API key must not be able to see, edit or
 * even confirm the existence of another academy's data.
 *
 * The expected status is 404 rather than 403 throughout — a 403 would confirm
 * the record exists, which is the enumeration threat of docs/12 §1 (T10).
 */
final class TenantIsolationApiTest extends ApiTestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_key_cannot_read_another_academys_student(): void
    {
        $alpha = $this->academy('alpha');
        $beta = $this->academy('beta');

        $foreign = TenantContext::runFor($beta, static fn (): Student => Student::factory()->create());

        ['plain_text' => $token] = $this->apiKeyFor($alpha, ['*']);

        $this->getJson('/api/v1/students/'.$foreign->getKey(), $this->keyHeaders($token))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    #[Test]
    public function a_key_cannot_update_or_delete_another_academys_student(): void
    {
        $alpha = $this->academy('alpha');
        $beta = $this->academy('beta');

        $foreign = TenantContext::runFor($beta, static fn (): Student => Student::factory()->create());

        ['plain_text' => $token] = $this->apiKeyFor($alpha, ['*']);

        $this->patchJson(
            '/api/v1/students/'.$foreign->getKey(),
            ['first_name' => 'Hijacked'],
            $this->keyHeaders($token)
        )->assertStatus(404);

        $this->deleteJson('/api/v1/students/'.$foreign->getKey(), [], $this->keyHeaders($token))
            ->assertStatus(404);

        $this->assertSame(
            $foreign->first_name,
            TenantContext::runFor($beta, static fn (): ?string => Student::query()
                ->find($foreign->getKey())?->first_name)
        );
    }

    #[Test]
    public function a_listing_never_contains_a_foreign_row(): void
    {
        $alpha = $this->academy('alpha');
        $beta = $this->academy('beta');

        TenantContext::runFor($alpha, static function (): void {
            Student::factory()->count(2)->create();
        });

        TenantContext::runFor($beta, static function (): void {
            Student::factory()->count(7)->create();
        });

        ['plain_text' => $token] = $this->apiKeyFor($alpha, ['*']);

        $this->getJson('/api/v1/students', $this->keyHeaders($token))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }
}
