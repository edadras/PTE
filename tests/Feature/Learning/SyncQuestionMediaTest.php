<?php

declare(strict_types=1);

namespace Tests\Feature\Learning;

use App\Domain\Learning\Actions\SyncQuestionMedia;
use App\Domain\Learning\Enums\MediaKind;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionMedia;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The bridge between where the panel puts a file and where the Telegram sender
 * looks for its cached file_id. Without a row here every send re-uploads the
 * same audio, which is slow for the student and pure waste on our side.
 */
final class SyncQuestionMediaTest extends TestCase
{
    use RefreshDatabase;

    private Academy $academy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->academy = Academy::factory()->create(['slug' => 'media']);
        TenantContext::set($this->academy);
    }

    #[Test]
    public function it_creates_a_row_for_each_media_key_in_the_content(): void
    {
        $question = $this->questionWith(['audio_key' => 'q/1/audio.mp3', 'image_key' => 'q/1/pic.png']);

        app(SyncQuestionMedia::class)->handle($question);

        $rows = QuestionMedia::query()->where('question_id', $question->getKey())->get();

        $this->assertCount(2, $rows);
        $this->assertSame(
            ['q/1/audio.mp3', 'q/1/pic.png'],
            $rows->sortBy('s3_path')->pluck('s3_path')->values()->all()
        );
    }

    #[Test]
    public function running_twice_does_not_duplicate_rows(): void
    {
        $question = $this->questionWith(['audio_key' => 'q/1/audio.mp3']);

        app(SyncQuestionMedia::class)->handle($question);
        app(SyncQuestionMedia::class)->handle($question);

        $this->assertSame(1, QuestionMedia::query()->where('question_id', $question->getKey())->count());
    }

    #[Test]
    public function removing_a_key_prunes_its_row(): void
    {
        $question = $this->questionWith(['audio_key' => 'q/1/audio.mp3', 'image_key' => 'q/1/pic.png']);
        app(SyncQuestionMedia::class)->handle($question);

        $question->content = ['audio_key' => 'q/1/audio.mp3'];
        $question->save();

        app(SyncQuestionMedia::class)->handle($question);

        $rows = QuestionMedia::query()->where('question_id', $question->getKey())->get();
        $this->assertCount(1, $rows);
        $this->assertSame(MediaKind::Audio, $rows->first()?->kind);
    }

    #[Test]
    public function replacing_the_file_drops_the_cached_telegram_id(): void
    {
        // A stale file_id would keep sending the *previous* recording forever —
        // the failure would look like the edit silently not saving.
        $question = $this->questionWith(['audio_key' => 'q/1/audio.mp3']);
        app(SyncQuestionMedia::class)->handle($question);

        QuestionMedia::query()->where('question_id', $question->getKey())->update([
            'telegram_file_id' => 'CACHED_ID',
            'telegram_bot_id' => 7,
            'cached_at' => now(),
        ]);

        $question->content = ['audio_key' => 'q/1/audio-v2.mp3'];
        $question->save();

        app(SyncQuestionMedia::class)->handle($question);

        $media = QuestionMedia::query()->where('question_id', $question->getKey())->firstOrFail();

        $this->assertSame('q/1/audio-v2.mp3', $media->s3_path);
        $this->assertNull($media->telegram_file_id);
        $this->assertNull($media->cached_at);
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function questionWith(array $content): Question
    {
        /** @var Question $question */
        $question = Question::factory()->create(['content' => $content]);

        return $question;
    }
}
