<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\AnswerResource\Pages;

use App\Filament\Academy\Resources\AnswerResource;
use Filament\Resources\Pages\ListRecords;

final class ListAnswers extends ListRecords
{
    protected static string $resource = AnswerResource::class;
}
