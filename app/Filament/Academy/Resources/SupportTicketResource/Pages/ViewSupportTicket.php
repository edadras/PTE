<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\SupportTicketResource\Pages;

use App\Domain\Support\Actions\AssignTicket;
use App\Domain\Support\Actions\CloseTicket;
use App\Domain\Support\Actions\ReplyToTicket;
use App\Domain\Support\Enums\TicketSender;
use App\Domain\Support\Models\SupportTicket;
use App\Domain\Support\Models\SupportTicketMessage;
use App\Filament\Academy\Resources\SupportTicketResource;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Throwable;

final class ViewSupportTicket extends ViewRecord
{
    protected static string $resource = SupportTicketResource::class;

    protected static string $view = 'filament.academy.pages.support-ticket-thread';

    public function getTitle(): string
    {
        return '#'.$this->ticket()->getKey().' · '.$this->ticket()->subject;
    }

    /**
     * The full thread, internal notes included — the blade badges them so an
     * agent always knows what the student can and cannot see.
     *
     * @return Collection<int, SupportTicketMessage>
     */
    public function getMessages(): Collection
    {
        return $this->ticket()
            ->messages()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<int, Actions\Action|Actions\ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->replyAction(),
            $this->noteAction(),
            $this->assignAction(),
            $this->closeAction(),
        ];
    }

    private function replyAction(): Actions\Action
    {
        return Actions\Action::make('reply')
            ->label(__('panel.tickets.action.reply'))
            ->icon('heroicon-o-chat-bubble-left-right')
            ->color('primary')
            ->visible(fn (): bool => auth()->user()?->can('support.tickets.reply') === true
                && ! $this->ticket()->isClosed())
            ->form([
                Textarea::make('content')
                    ->label(__('panel.tickets.field.reply'))
                    ->required()
                    ->rows(5),
            ])
            ->action(function (array $data): void {
                Gate::authorize('support.tickets.reply');

                try {
                    app(ReplyToTicket::class)->handle(
                        $this->ticket(),
                        (string) $data['content'],
                        TicketSender::Staff,
                        auth()->id(),
                    );

                    Notification::make()->success()->title(__('support.ticket.replied'))->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(__('panel.tickets.notify.failed'))->body($e->getMessage())->send();
                }

                $this->refreshFormData(['status', 'last_reply_at']);
            });
    }

    private function noteAction(): Actions\Action
    {
        return Actions\Action::make('note')
            ->label(__('panel.tickets.action.note'))
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('support.tickets.reply') === true
                && ! $this->ticket()->isClosed())
            ->form([
                Textarea::make('content')
                    ->label(__('support.ticket.internal_note'))
                    ->helperText(__('panel.tickets.help.note'))
                    ->required()
                    ->rows(4),
            ])
            ->action(function (array $data): void {
                Gate::authorize('support.tickets.reply');

                app(ReplyToTicket::class)->handle(
                    $this->ticket(),
                    (string) $data['content'],
                    TicketSender::Staff,
                    auth()->id(),
                    isInternal: true,
                );

                Notification::make()->success()->title(__('panel.tickets.notify.noted'))->send();
            });
    }

    private function assignAction(): Actions\Action
    {
        return Actions\Action::make('assign')
            ->label(__('panel.tickets.action.assign'))
            ->icon('heroicon-o-user-plus')
            ->visible(fn (): bool => auth()->user()?->can('support.tickets.reply') === true)
            ->form([
                Select::make('assigned_to')
                    ->label(__('panel.tickets.field.assignee'))
                    ->options(fn (): array => SupportTicketResource::assigneeOptions())
                    ->default(fn (): ?int => $this->ticket()->assigned_to)
                    ->placeholder(__('panel.tickets.unassigned')),
            ])
            ->action(function (array $data): void {
                Gate::authorize('support.tickets.reply');

                app(AssignTicket::class)->handle(
                    $this->ticket(),
                    filled($data['assigned_to'] ?? null) ? (int) $data['assigned_to'] : null,
                );

                Notification::make()->success()->title(__('support.ticket.assigned'))->send();

                $this->refreshFormData(['assigned_to']);
            });
    }

    private function closeAction(): Actions\Action
    {
        return Actions\Action::make('close')
            ->label(__('panel.tickets.action.close'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (): bool => auth()->user()?->can('support.tickets.close') === true
                && ! $this->ticket()->isClosed())
            ->form([
                Textarea::make('resolution')
                    ->label(__('panel.tickets.field.resolution'))
                    ->helperText(__('panel.tickets.help.resolution'))
                    ->rows(3),
            ])
            ->action(function (array $data): void {
                Gate::authorize('support.tickets.close');

                app(CloseTicket::class)->handle(
                    $this->ticket(),
                    auth()->id(),
                    filled($data['resolution'] ?? null) ? (string) $data['resolution'] : null,
                );

                Notification::make()->success()->title(__('support.ticket.closed'))->send();

                $this->refreshFormData(['status', 'closed_at']);
            });
    }

    private function ticket(): SupportTicket
    {
        /** @var SupportTicket $record */
        $record = $this->getRecord();

        return $record;
    }
}
