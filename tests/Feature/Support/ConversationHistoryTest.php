<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Domain\Identity\Models\Student;
use App\Domain\Support\Data\ConversationEntry;
use App\Domain\Support\Services\ConversationHistory;
use App\Domain\Telegram\Enums\MessageDirection;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Models\TelegramIdentity;
use App\Domain\Telegram\Models\TelegramMessage;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ConversationHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Academy $academy;

    private Student $student;

    private TelegramBot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->academy = Academy::factory()->configured()->create();
        TenantContext::set($this->academy);

        $this->student = Student::factory()->create();
        $this->bot = TelegramBot::factory()->create();
    }

    #[Test]
    public function it_returns_the_exchange_oldest_first(): void
    {
        $this->message('hello', MessageDirection::In, now()->subMinutes(10));
        $this->message('welcome', MessageDirection::Out, now()->subMinutes(9));
        $this->message('help', MessageDirection::In, now()->subMinutes(8));

        $history = app(ConversationHistory::class)->forStudent($this->student);

        $this->assertCount(3, $history);
        $this->assertSame(['hello', 'welcome', 'help'], $history->map(
            static fn (ConversationEntry $entry): string => $entry->content
        )->all());
        $this->assertTrue($history->first()?->isFromStudent());
    }

    #[Test]
    public function messages_past_the_retention_window_are_not_shown(): void
    {
        config()->set('pte.retention.telegram_messages', 90);

        $this->message('ancient', MessageDirection::In, now()->subDays(120));
        $this->message('recent', MessageDirection::In, now()->subDays(2));

        $history = app(ConversationHistory::class)->forStudent($this->student);

        $this->assertCount(1, $history);
        $this->assertSame('recent', $history->first()?->content);
        $this->assertSame(90, app(ConversationHistory::class)->horizonDays());
    }

    #[Test]
    public function it_finds_the_part_of_the_conversation_from_before_the_student_was_linked(): void
    {
        TelegramIdentity::factory()->create([
            'student_id' => $this->student->getKey(),
            'telegram_user_id' => 555,
            'chat_id' => 555,
        ]);

        // Sent before the identity was linked, so student_id is still null.
        TelegramMessage::query()->create([
            'telegram_bot_id' => $this->bot->getKey(),
            'student_id' => null,
            'chat_id' => 555,
            'direction' => MessageDirection::In,
            'message_type' => 'text',
            'content' => 'before linking',
            'status' => 'received',
            'created_at' => now()->subMinutes(30),
        ]);

        $this->message('after linking', MessageDirection::In, now()->subMinutes(5));

        $service = app(ConversationHistory::class);

        $this->assertCount(1, $service->forStudent($this->student));
        $this->assertCount(2, $service->forStudentIncludingUnlinked($this->student));
    }

    #[Test]
    public function history_never_crosses_the_academy_boundary(): void
    {
        $this->message('alpha only', MessageDirection::In, now());

        $other = Academy::factory()->configured()->create();

        TenantContext::runFor($other, function (): void {
            $student = Student::factory()->create();

            $this->assertCount(0, app(ConversationHistory::class)->forChat(555));
            $this->assertCount(0, app(ConversationHistory::class)->forStudent($student));
        });
    }

    private function message(string $content, MessageDirection $direction, DateTimeInterface $at): void
    {
        TelegramMessage::query()->create([
            'telegram_bot_id' => $this->bot->getKey(),
            'student_id' => $this->student->getKey(),
            'chat_id' => 555,
            'direction' => $direction,
            'message_type' => 'text',
            'content' => $content,
            'status' => 'sent',
            'created_at' => $at,
        ]);
    }
}
