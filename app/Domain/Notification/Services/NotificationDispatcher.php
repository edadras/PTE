<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Domain\Identity\Models\Student;
use App\Domain\Notification\Contracts\SmsDriver;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Models\Notification;
use App\Domain\Telegram\Data\OutgoingMessage;
use App\Domain\Telegram\Models\TelegramIdentity;
use App\Domain\Telegram\Services\MessageSender;
use App\Domain\Tenancy\Data\PlaceholderContext;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\Services\MessageTemplateResolver;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * One entry point for every message the platform sends outside Telegram flows.
 *
 * Copy always comes from MessageTemplateResolver, never from a string literal
 * here: an academy that customised its "daily nudge" must see that text on
 * every channel, and a platform-wide improvement to the default must reach the
 * academies that never edited it (docs/03 §6).
 *
 * Delivery never throws. A notification that could not be sent is a row with a
 * status and an error, because the alternative — a failed job — loses the fact
 * that we tried at all.
 *
 * @see docs/07-database-schema.md §10
 */
final class NotificationDispatcher
{
    public function __construct(
        private readonly MessageTemplateResolver $templates,
        private readonly MessageSender $telegram,
    ) {}

    /**
     * Create (and unless scheduled, deliver) one notification per channel.
     *
     * @param  array<int, NotificationChannel>  $channels
     * @param  array<string, mixed>  $data
     * @return Collection<int, Notification>
     */
    public function send(
        Model $notifiable,
        string $templateKey,
        array $data = [],
        array $channels = [NotificationChannel::Telegram],
        ?Carbon $scheduledAt = null,
        ?PlaceholderContext $context = null,
    ): Collection {
        $academy = TenantContext::require();
        $context ??= PlaceholderContext::make($data)->forAcademy($academy);

        return collect($channels)
            ->map(function (NotificationChannel $channel) use ($notifiable, $templateKey, $data, $scheduledAt, $context, $academy): Notification {
                $notification = $this->record($notifiable, $channel, $templateKey, $data, $scheduledAt);

                if ($scheduledAt instanceof Carbon && $scheduledAt->isFuture()) {
                    return $notification;
                }

                $this->deliver($notification, $context, $academy);

                return $notification;
            })
            ->values();
    }

    /**
     * Deliver a stored notification. Safe to call again on a failed row.
     */
    public function deliver(Notification $notification, ?PlaceholderContext $context = null, ?Academy $academy = null): bool
    {
        $academy ??= TenantContext::require();
        $context ??= PlaceholderContext::make($notification->data ?? [])->forAcademy($academy);

        try {
            $body = $this->body($notification, $context, $academy);

            if ($body === '') {
                $notification->markSkipped('Template resolved to an empty message.');

                return false;
            }

            $sent = match ($notification->channel) {
                NotificationChannel::Telegram => $this->viaTelegram($notification, $body),
                NotificationChannel::Email => $this->viaEmail($notification, $body, $academy),
                NotificationChannel::Sms => $this->viaSms($notification, $body),
            };

            if ($sent) {
                $notification->markSent();
            }

            return $sent;
        } catch (Throwable $e) {
            $notification->markFailed($e->getMessage());

            return false;
        }
    }

    /**
     * Deliver everything that has come due. Used by the notifications queue and
     * by the scheduled-content sweep.
     */
    public function flushDue(?Carbon $moment = null, int $limit = 200): int
    {
        $sent = 0;

        Notification::query()
            ->due($moment)
            ->with('notifiable')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (Notification $notification) use (&$sent): void {
                if ($this->deliver($notification)) {
                    $sent++;
                }
            });

        return $sent;
    }

    /**
     * The rendered copy for this notification.
     *
     * `body` in the payload wins — jobs that compose their own text (the weekly
     * progress report) pass it through rather than pretending it is a template.
     */
    public function body(Notification $notification, PlaceholderContext $context, Academy $academy): string
    {
        $override = $notification->data['body'] ?? null;

        $text = is_string($override) && $override !== ''
            ? $override
            : $this->templates->render(
                key: $notification->type,
                context: $context,
                locale: $academy->preferredLocale(),
                channel: $notification->channel->templateChannel(),
                academy: $academy,
                escape: $notification->channel === NotificationChannel::Telegram,
            );

        $max = $notification->channel->maxLength();

        return $max === null ? $text : Str::limit($text, $max, '');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function record(
        Model $notifiable,
        NotificationChannel $channel,
        string $templateKey,
        array $data,
        ?Carbon $scheduledAt,
    ): Notification {
        /** @var Notification $notification */
        $notification = Notification::query()->create([
            'notifiable_type' => $notifiable::class,
            'notifiable_id' => $notifiable->getKey(),
            'channel' => $channel,
            'type' => $templateKey,
            'data' => $data,
            'scheduled_at' => $scheduledAt,
            'status' => $scheduledAt instanceof Carbon && $scheduledAt->isFuture()
                ? NotificationStatus::Queued
                : NotificationStatus::Pending,
        ]);

        // The recipient is already in hand; priming the relation saves a query
        // per notification and keeps delivery working under strict-mode lazy
        // loading, which forbids resolving it later.
        $notification->setRelation('notifiable', $notifiable);

        return $notification;
    }

    private function viaTelegram(Notification $notification, string $body): bool
    {
        $notifiable = $notification->notifiable;

        if (! $notifiable instanceof Student) {
            $notification->markSkipped('Telegram delivery needs a student.');

            return false;
        }

        $identity = TelegramIdentity::query()
            ->where('student_id', $notifiable->getKey())
            ->where('is_blocked', false)
            ->orderByDesc('last_interaction_at')
            ->first();

        if (! $identity instanceof TelegramIdentity) {
            $notification->markSkipped('Student has no reachable Telegram chat.');

            return false;
        }

        // Queued, not sent inline: MessageSender's per-chat rate limit would
        // otherwise reject a burst of nudges and lose them.
        $this->telegram->queue(
            OutgoingMessage::text((int) $identity->chat_id, $body)
                ->forChat((int) $identity->chat_id, (int) $notifiable->getKey()),
            (int) $notification->academy_id,
        );

        return true;
    }

    private function viaEmail(Notification $notification, string $body, Academy $academy): bool
    {
        $address = $this->emailFor($notification->notifiable);

        if ($address === null) {
            $notification->markSkipped('No email address on file.');

            return false;
        }

        $subject = is_string($notification->data['subject'] ?? null) && $notification->data['subject'] !== ''
            ? (string) $notification->data['subject']
            : ($academy->brand?->display_name ?? $academy->name);

        Mail::raw($body, static function ($message) use ($address, $subject): void {
            $message->to($address)->subject($subject);
        });

        return true;
    }

    private function viaSms(Notification $notification, string $body): bool
    {
        $driver = $this->smsDriver();

        if (! $driver->isAvailable()) {
            $notification->markSkipped("SMS driver [{$driver->name()}] cannot deliver.");

            return false;
        }

        $number = $this->phoneFor($notification->notifiable);

        if ($number === null) {
            $notification->markSkipped('No phone number on file.');

            return false;
        }

        if (! $driver->send($number, $body)) {
            $notification->markFailed("SMS driver [{$driver->name()}] rejected the message.");

            return false;
        }

        return true;
    }

    /**
     * Resolved from the container so a deployment can bind a real gateway
     * without this class knowing which one.
     */
    private function smsDriver(): SmsDriver
    {
        return app()->bound(SmsDriver::class) ? app(SmsDriver::class) : new NullSmsDriver;
    }

    private function emailFor(?Model $notifiable): ?string
    {
        if ($notifiable instanceof User) {
            $email = $notifiable->getAttribute('email');

            return is_string($email) && $email !== '' ? $email : null;
        }

        if ($notifiable instanceof Student) {
            $email = $notifiable->getAttribute('email');

            return is_string($email) && $email !== '' ? $email : null;
        }

        return null;
    }

    private function phoneFor(?Model $notifiable): ?string
    {
        $phone = $notifiable?->getAttribute('phone');

        return is_string($phone) && $phone !== '' ? $phone : null;
    }
}
