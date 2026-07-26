<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\ApiKey;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The surfaces added after the first panel pass: flows, tickets, domains,
 * API keys, webhooks and message templates.
 *
 * Each one either exposes a secret or changes how the bot behaves, so the
 * checks here are about who may open them, not about how they look.
 */
final class NewPanelSurfacesTest extends FilamentTestCase
{
    use RefreshDatabase;

    private Academy $academy;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->academy = $this->makeAcademy('surfaces');
        $this->owner = $this->makeStaff($this->academy, SystemRole::Owner);
        $this->forgetHostCache($this->academy);
    }

    protected function tearDown(): void
    {
        $this->tearDownTenant();

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ownerPages(): array
    {
        return [
            'flows' => ['telegram-flows'],
            'support tickets' => ['support-tickets'],
            'domains' => ['domains'],
            'api keys' => ['api-keys'],
            'webhooks' => ['webhooks'],
            'message templates' => ['message-templates'],
        ];
    }

    #[Test]
    #[DataProvider('ownerPages')]
    public function an_owner_can_open_every_new_surface(string $path): void
    {
        $this->actingAs($this->owner)
            ->get($this->panelUrl($this->academy, $path))
            ->assertSuccessful();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ownerOnlyPages(): array
    {
        return [
            'domains' => ['domains'],
            'api keys' => ['api-keys'],
            'webhooks' => ['webhooks'],
        ];
    }

    #[Test]
    #[DataProvider('ownerOnlyPages')]
    public function a_teacher_is_refused_the_owner_only_surfaces(string $path): void
    {
        // These three hand out credentials or repoint traffic. A teacher holding
        // none of those permissions must not even see the page.
        $teacher = $this->makeStaff($this->academy, SystemRole::Teacher);

        $this->actingAs($teacher)
            ->get($this->panelUrl($this->academy, $path))
            ->assertForbidden();
    }

    #[Test]
    public function a_member_of_another_academy_is_refused(): void
    {
        $other = $this->makeAcademy('outsider');
        $stranger = $this->makeStaff($other, SystemRole::Owner);

        $this->actingAs($stranger)
            ->get($this->panelUrl($this->academy, 'api-keys'))
            ->assertForbidden();
    }

    #[Test]
    public function an_issued_api_key_is_only_ever_readable_once(): void
    {
        TenantContext::set($this->academy);

        [$key, $plain] = array_values(ApiKey::issue('CI integration', ['students:read'], $this->owner));

        $this->assertIsString($plain);
        $this->assertNotSame('', $plain);

        // Only the hash and the last four survive; the plaintext is gone the
        // moment the creation response is rendered.
        $fresh = ApiKey::query()->findOrFail($key->getKey());

        $this->assertNotSame($plain, $fresh->token_hash);
        $this->assertSame(substr($plain, -4), $fresh->last_four);
        $this->assertNotContains('token_hash', array_keys($fresh->toArray()));
    }
}
