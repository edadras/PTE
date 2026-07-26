<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academy;

use App\Domain\Identity\Models\Student;
use App\Domain\Telegram\Actions\StartBroadcast;
use App\Domain\Telegram\Data\OutgoingMessage;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Models\TelegramIdentity;
use App\Domain\Telegram\Services\MessageSender;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Academy\SendTelegramMessageRequest;
use App\Http\Requests\Api\Academy\StartBroadcastRequest;
use App\Http\Resources\TelegramBotResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `/api/v1/telegram/*` — docs/08 §3.
 *
 * Sends are queued rather than performed inline: MessageSender's per-bot and
 * per-chat limiters would otherwise turn an integration's loop into a 429
 * storm against Telegram itself.
 */
final class TelegramController extends ApiController
{
    public function send(SendTelegramMessageRequest $request, MessageSender $sender): JsonResponse
    {
        $validated = $request->validated();

        $student = Student::query()->findOrFail((int) $validated['student_id']);

        $identity = TelegramIdentity::query()
            ->reachable()
            ->where('student_id', $student->getKey())
            ->first();

        if (! $identity instanceof TelegramIdentity) {
            throw new NotFoundHttpException(__('api.errors.student_not_reachable'));
        }

        $message = OutgoingMessage::text(
            (int) $identity->chat_id,
            (string) $validated['text'],
            $this->keyboard($validated['buttons'] ?? []),
        )->forChat((int) $identity->chat_id, (int) $student->getKey());

        $sender->queue($message);

        return $this->payload($request, [
            'status' => 'queued',
            'student_id' => (int) $student->getKey(),
        ], status: 202);
    }

    public function broadcast(StartBroadcastRequest $request, StartBroadcast $action): JsonResponse
    {
        $validated = $request->validated();

        $broadcast = $action->handle($action->create(
            title: (string) $validated['title'],
            content: (array) $validated['content'],
            audienceFilter: (array) ($validated['audience'] ?? []),
            scheduledAt: isset($validated['scheduled_at'])
                ? Carbon::parse((string) $validated['scheduled_at'])
                : null,
        ));

        return $this->payload($request, [
            'id' => (int) $broadcast->getKey(),
            'status' => $broadcast->status->value,
            'scheduled_at' => $broadcast->scheduled_at?->toIso8601String(),
        ], status: 202);
    }

    public function bot(): TelegramBotResource
    {
        $bot = TelegramBot::query()->orderByDesc('is_active')->first();

        if (! $bot instanceof TelegramBot) {
            throw new NotFoundHttpException(__('api.errors.no_bot_connected'));
        }

        return TelegramBotResource::make($bot);
    }

    /**
     * @param  array<int, array<string, mixed>>  $buttons
     * @return array<string, mixed>|null
     */
    private function keyboard(array $buttons): ?array
    {
        if ($buttons === []) {
            return null;
        }

        return [
            'inline_keyboard' => array_map(
                static fn (array $button): array => [array_filter([
                    'text' => (string) $button['text'],
                    'url' => $button['url'] ?? null,
                    'callback_data' => $button['callback_data'] ?? null,
                ], static fn (mixed $value): bool => $value !== null)],
                array_values($buttons),
            ),
        ];
    }
}
