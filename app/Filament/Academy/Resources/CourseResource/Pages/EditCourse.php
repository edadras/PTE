<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\CourseResource\Pages;

use App\Filament\Academy\Resources\CourseResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

final class EditCourse extends EditRecord
{
    protected static string $resource = CourseResource::class;

    /**
     * @return array<int, Actions\Action|Actions\ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
