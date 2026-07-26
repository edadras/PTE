<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources;

use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Enums\TicketSource;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\SupportTicket;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Academy\Resources\SupportTicketResource\Pages;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The support queue (docs/07 §10). Every mutation — open, reply, note, assign,
 * close — goes through the Support context's actions, so the panel and the
 * Telegram bot keep one shared ticket lifecycle.
 */
final class SupportTicketResource extends Resource
{
    protected static ?string $model = SupportTicket::class;

    protected static ?string $navigationIcon = 'heroicon-o-lifebuoy';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.people');
    }

    public static function getModelLabel(): string
    {
        return __('panel.tickets.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.tickets.plural');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('support.tickets.view') === true;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = SupportTicket::query()->open()->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                Tables\Columns\TextColumn::make('subject')
                    ->label(__('panel.tickets.field.subject'))
                    ->searchable()
                    ->limit(50),
                Tables\Columns\TextColumn::make('student.first_name')
                    ->label(__('panel.students.singular'))
                    ->formatStateUsing(fn (SupportTicket $record): string => trim(
                        (string) $record->student?->first_name.' '.(string) $record->student?->last_name
                    ))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('panel.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (TicketStatus $state): string => $state->label())
                    ->color(fn (TicketStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('priority')
                    ->label(__('panel.tickets.field.priority'))
                    ->badge()
                    ->formatStateUsing(fn (TicketPriority $state): string => $state->label())
                    ->color(fn (TicketPriority $state): string => match ($state) {
                        TicketPriority::Urgent => 'danger',
                        TicketPriority::High => 'warning',
                        TicketPriority::Normal => 'info',
                        TicketPriority::Low => 'gray',
                    }),
                Tables\Columns\TextColumn::make('source')
                    ->label(__('panel.tickets.field.source'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (TicketSource $state): string => $state->label())
                    ->toggleable(),
                Tables\Columns\TextColumn::make('assignee.name')
                    ->label(__('panel.tickets.field.assignee'))
                    ->placeholder(__('panel.tickets.unassigned')),
                Tables\Columns\TextColumn::make('last_reply_at')
                    ->label(__('panel.tickets.field.last_reply'))
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('panel.common.status'))
                    ->options(fn (): array => TicketStatus::options()),
                Tables\Filters\SelectFilter::make('priority')
                    ->label(__('panel.tickets.field.priority'))
                    ->options(fn (): array => TicketPriority::options()),
                Tables\Filters\Filter::make('mine')
                    ->label(__('panel.tickets.filter.mine'))
                    ->query(fn (Builder $query): Builder => $query->where('assigned_to', auth()->id())),
                Tables\Filters\Filter::make('unassigned')
                    ->label(__('panel.tickets.filter.unassigned'))
                    ->query(fn (Builder $query): Builder => $query->whereNull('assigned_to')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('last_reply_at', 'desc');
    }

    /**
     * Staff of this academy only — `assigned_to` crosses into the platform
     * users table, so the membership relation is the safe picker source.
     *
     * @return array<int, string>
     */
    public static function assigneeOptions(): array
    {
        return TenantContext::require()
            ->users()
            ->orderBy('name')
            ->pluck('users.name', 'users.id')
            ->all();
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupportTickets::route('/'),
            'view' => Pages\ViewSupportTicket::route('/{record}'),
        ];
    }
}
