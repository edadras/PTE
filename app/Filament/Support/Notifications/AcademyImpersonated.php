<?php

declare(strict_types=1);

namespace App\Filament\Support\Notifications;

use App\Domain\Tenancy\Models\Academy;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Somebody from support just opened your panel." Mandatory under docs/02 §2 —
 * impersonation without owner notification is not permitted.
 */
final class AcademyImpersonated extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Academy $academy,
        private readonly User $actor,
        private readonly int $minutes,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('panel.impersonation.mail.subject', ['academy' => $this->academy->name]))
            ->line(__('panel.impersonation.mail.intro', [
                'actor' => $this->actor->name,
                'academy' => $this->academy->name,
            ]))
            ->line(__('panel.impersonation.mail.window', ['minutes' => $this->minutes]))
            ->line(__('panel.impersonation.mail.contact', [
                'email' => (string) config('pte.platform.support_email'),
            ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'academy_id' => $this->academy->getKey(),
            'actor_id' => $this->actor->getKey(),
            'minutes' => $this->minutes,
        ];
    }
}
