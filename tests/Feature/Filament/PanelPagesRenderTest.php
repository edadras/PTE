<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Tenancy\Models\Academy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every list page, create page and settings page of both panels has to render.
 *
 * This is the cheap net that catches a typo in a column closure or a missing
 * translation key before a customer does.
 */
final class PanelPagesRenderTest extends FilamentTestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_platform_page_renders_for_a_super_admin(): void
    {
        $admin = User::factory()->superAdmin()->create();

        foreach ($this->parameterlessGetRoutes('platform/') as $uri) {
            $response = $this->actingAs($admin)->get('/'.$uri);

            $this->assertTrue(
                $response->isSuccessful() || $response->isRedirect(),
                sprintf('Platform route [%s] returned %d.', $uri, $response->status()),
            );
        }
    }

    #[Test]
    public function every_academy_page_renders_for_an_owner(): void
    {
        $academy = $this->makeAcademy('delta');
        $this->forgetHostCache($academy);

        $owner = $this->makeStaff($academy, SystemRole::Owner);

        foreach ($this->parameterlessGetRoutes('panel/') as $uri) {
            // Signed / one-time entry points are covered by their own tests.
            if (str_contains($uri, 'impersonation') || str_contains($uri, 'password-reset')) {
                continue;
            }

            $response = $this->actingAs($owner)->get($this->url($academy, $uri));

            $this->assertTrue(
                $response->isSuccessful() || $response->isRedirect(),
                sprintf('Academy route [%s] returned %d.', $uri, $response->status()),
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function parameterlessGetRoutes(string $prefix): array
    {
        $uris = [];

        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $uri = $route->uri();

            if (! str_starts_with($uri, $prefix)
                || str_contains($uri, '{')
                || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uris[] = $uri;
        }

        return array_values(array_unique($uris));
    }

    private function url(Academy $academy, string $uri): string
    {
        return 'http://'.$this->hostFor($academy).'/'.$uri;
    }
}
