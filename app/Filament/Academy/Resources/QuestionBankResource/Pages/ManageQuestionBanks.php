<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\QuestionBankResource\Pages;

use App\Filament\Academy\Resources\QuestionBankResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

final class ManageQuestionBanks extends ManageRecords
{
    protected static string $resource = QuestionBankResource::class;

    /**
     * @return array<int, Actions\Action|Actions\ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
