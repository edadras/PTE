<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\StudentResource\Pages;

use App\Domain\Identity\Models\Student;
use App\Filament\Academy\Resources\StudentResource;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

final class ViewStudent extends ViewRecord
{
    protected static string $resource = StudentResource::class;

    /**
     * @return array<int, Actions\Action|Actions\ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('panel.students.section.identity'))
                ->schema([
                    Infolists\Components\TextEntry::make('student_code')->label(__('panel.students.field.code')),
                    Infolists\Components\TextEntry::make('first_name')
                        ->label(__('panel.students.field.name'))
                        ->state(fn (Student $record): string => $record->fullName()),
                    Infolists\Components\TextEntry::make('status')
                        ->label(__('panel.students.field.status'))
                        ->badge()
                        ->formatStateUsing(fn ($state): string => $state->label()),
                    Infolists\Components\TextEntry::make('level')->label(__('panel.students.field.level'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('target_score')->label(__('panel.students.field.target_score'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('last_active_at')->label(__('panel.students.field.last_active'))->dateTime()->placeholder('—'),
                ])
                ->columns(3),
        ]);
    }
}
