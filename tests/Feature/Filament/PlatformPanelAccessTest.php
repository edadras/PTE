<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The platform panel is gated on the `is_super_admin` column, never on a role
 * (docs/02 §1). A staff account — even an academy owner — must bounce off it.
 */
final class PlatformPanelAccessTest extends FilamentTestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_platform_panel_is_forbidden_for_a_non_super_admin(): void
    {
        $user = User::factory()->create(['is_super_admin' => false]);

        $this->actingAs($user)
            ->get('/platform')
            ->assertForbidden();
    }

    #[Test]
    public function the_platform_panel_opens_for_a_super_admin(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->get('/platform')
            ->assertSuccessful();
    }

    #[Test]
    public function an_anonymous_visitor_is_sent_to_the_login_screen(): void
    {
        $this->get('/platform')->assertRedirect('/platform/login');
    }
}
