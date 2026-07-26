<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Tenancy\Models\Academy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * docs/02 §6 names this test explicitly: a Manager must not reach the bot
 * token. The Owner must, otherwise the gate is simply broken shut.
 */
final class BotTokenPageAccessTest extends FilamentTestCase
{
    use RefreshDatabase;

    private Academy $academy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->academy = $this->makeAcademy('gamma');
        $this->forgetHostCache($this->academy);
    }

    #[Test]
    public function a_manager_cannot_open_the_bot_token_page(): void
    {
        $manager = $this->makeStaff($this->academy, SystemRole::Manager);

        $this->actingAs($manager)
            ->get($this->panelUrl($this->academy, 'telegram-bot-settings'))
            ->assertForbidden();
    }

    #[Test]
    public function an_owner_can_open_the_bot_token_page(): void
    {
        $owner = $this->makeStaff($this->academy, SystemRole::Owner);

        $this->actingAs($owner)
            ->get($this->panelUrl($this->academy, 'telegram-bot-settings'))
            ->assertSuccessful();
    }

    #[Test]
    public function a_teacher_cannot_open_the_ai_settings_page(): void
    {
        $teacher = $this->makeStaff($this->academy, SystemRole::Teacher);

        $this->actingAs($teacher)
            ->get($this->panelUrl($this->academy, 'ai-settings'))
            ->assertForbidden();
    }
}
