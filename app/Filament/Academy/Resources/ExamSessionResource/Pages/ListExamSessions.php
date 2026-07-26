<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\ExamSessionResource\Pages;

use App\Filament\Academy\Resources\ExamSessionResource;
use Filament\Resources\Pages\ListRecords;

final class ListExamSessions extends ListRecords
{
    protected static string $resource = ExamSessionResource::class;
}
