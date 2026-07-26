<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\SupportTicketResource\Pages;

use App\Domain\Identity\Models\Student;
use App\Domain\Support\Actions\OpenTicket;
use App\Domain\Support\Data\OpenTicketData;
use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Enums\TicketSource;
use App\Filament\Academy\Resources\SupportTicketResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Gate;

final class ListSupportTickets extends ListRecords
{
    protected static string $resource = SupportTicketResource::class;

    /**
     * @return array<int, Actions\Action|Actions\ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('open')
                ->label(__('panel.tickets.action.open'))
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => auth()->user()?->can('support.tickets.reply') === true)
                ->form([
                    Forms\Components\Select::make('student_id')
                        ->label(__('panel.students.singular'))
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Student::query()
                            ->where(fn ($query) => $query
                                ->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('student_code', 'like', "%{$search}%"))
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (Student $student): array => [
                                $student->getKey() => trim($student->first_name.' '.(string) $student->last_name),
                            ])
                            ->all())
                        ->getOptionLabelUsing(fn (mixed $value): ?string => Student::query()
                            ->find($value)
                            ?->first_name),
                    Forms\Components\TextInput::make('subject')
                        ->label(__('panel.tickets.field.subject'))
                        ->required()
                        ->maxLength(180),
                    Forms\Components\Textarea::make('message')
                        ->label(__('panel.tickets.field.message'))
                        ->required()
                        ->rows(4),
                    Forms\Components\Select::make('priority')
                        ->label(__('panel.tickets.field.priority'))
                        ->options(fn (): array => TicketPriority::options())
                        ->default(TicketPriority::Normal->value),
                ])
                ->action(function (array $data) {
                    Gate::authorize('support.tickets.reply');

                    $ticket = app(OpenTicket::class)->handle(new OpenTicketData(
                        subject: (string) $data['subject'],
                        message: (string) $data['message'],
                        studentId: filled($data['student_id'] ?? null) ? (int) $data['student_id'] : null,
                        priority: TicketPriority::tryFrom((string) ($data['priority'] ?? '')) ?? TicketPriority::Normal,
                        source: TicketSource::Panel,
                    ));

                    Notification::make()->success()->title(__('support.ticket.opened'))->send();

                    return redirect(SupportTicketResource::getUrl('view', ['record' => $ticket]));
                }),
        ];
    }
}
