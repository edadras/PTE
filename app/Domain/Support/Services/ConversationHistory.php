<?php

declare(strict_types=1);

namespace App\Domain\Support\Services;

use App\Domain\Identity\Models\Student;
use App\Domain\Support\Data\ConversationEntry;
use App\Domain\Telegram\Enums\MessageDirection;
use App\Domain\Telegram\Models\TelegramIdentity;
use App\Domain\Telegram\Models\TelegramMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "What did the bot actually say to this student?" — the first question support
 * asks and the one the panel could not answer without this.
 *
 * Reads `telegram_messages`, which is pruned at
 * `config('pte.retention.telegram_messages')` days. The horizon is applied to
 * the query as well as trusted to the pruner, so the window support sees is the
 * documented one whether or not the sweep has run yet, and `horizonDays()` lets
 * the UI say "older messages are no longer retained" instead of implying the
 * student never wrote anything.
 *
 * @see docs/07-database-schema.md §11 · docs/10-infrastructure-and-ops.md §8
 */
final class ConversationHistory
{
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 500;

    /**
     * @return Collection<int, ConversationEntry>
     */
    public function forStudent(Student|int $student, int $limit = self::DEFAULT_LIMIT): Collection
    {
        $studentId = $student instanceof Student ? (int) $student->getKey() : $student;

        return $this->entries(
            TelegramMessage::query()->where('student_id', $studentId),
            $limit,
        );
    }

    /**
     * @return Collection<int, ConversationEntry>
     */
    public function forChat(int $chatId, int $limit = self::DEFAULT_LIMIT): Collection
    {
        return $this->entries(
            TelegramMessage::query()->where('chat_id', $chatId),
            $limit,
        );
    }

    /**
     * Everything the bot exchanged with a student, including the messages sent
     * before they were linked to a Student row — a support case very often
     * starts exactly there, in the unlinked part of the conversation.
     *
     * @return Collection<int, ConversationEntry>
     */
    public function forStudentIncludingUnlinked(Student|int $student, int $limit = self::DEFAULT_LIMIT): Collection
    {
        $studentId = $student instanceof Student ? (int) $student->getKey() : $student;

        $chatIds = TelegramIdentity::query()
            ->where('student_id', $studentId)
            ->pluck('chat_id')
            ->map(intval(...))
            ->all();

        return $this->entries(
            TelegramMessage::query()->where(function ($query) use ($studentId, $chatIds): void {
                $query->where('student_id', $studentId);

                if ($chatIds !== []) {
                    $query->orWhereIn('chat_id', $chatIds);
                }
            }),
            $limit,
        );
    }

    /** Number of retained messages, for a "showing N of M" header. */
    public function countForStudent(Student|int $student): int
    {
        $studentId = $student instanceof Student ? (int) $student->getKey() : $student;

        return TelegramMessage::query()
            ->where('student_id', $studentId)
            ->where('created_at', '>=', $this->horizon())
            ->count();
    }

    public function horizonDays(): int
    {
        return max(1, (int) config('pte.retention.telegram_messages', 90));
    }

    public function horizon(): Carbon
    {
        return now()->subDays($this->horizonDays());
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<TelegramMessage>  $query
     * @return Collection<int, ConversationEntry>
     */
    private function entries($query, int $limit): Collection
    {
        $rows = $query
            ->where('created_at', '>=', $this->horizon())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max(1, min($limit, self::MAX_LIMIT)))
            ->get(['id', 'direction', 'message_type', 'content', 'status', 'error', 'created_at']);

        return $rows
            ->map(static fn (TelegramMessage $message): ConversationEntry => new ConversationEntry(
                id: (int) $message->getKey(),
                direction: $message->direction instanceof MessageDirection
                    ? $message->direction
                    : MessageDirection::Out,
                type: (string) $message->message_type,
                content: (string) ($message->content ?? ''),
                status: (string) ($message->status ?? ''),
                error: $message->error === null ? null : (string) $message->error,
                at: $message->created_at,
            ))
            ->reverse()
            ->values();
    }
}
