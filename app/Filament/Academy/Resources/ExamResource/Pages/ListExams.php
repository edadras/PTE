<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\ExamResource\Pages;

use App\Filament\Academy\Resources\ExamResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListExams extends ListRecords
{
    protected static string $resource = ExamResource::class;

    /**
     * @return array<int, Actions\Action|Actions\ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
