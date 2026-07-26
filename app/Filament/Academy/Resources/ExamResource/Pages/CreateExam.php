<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\ExamResource\Pages;

use App\Domain\Assessment\Models\Exam;
use App\Filament\Academy\Resources\ExamResource;
use App\Filament\Academy\Support\ExamSectionSync;
use Filament\Resources\Pages\CreateRecord;

final class CreateExam extends CreateRecord
{
    protected static string $resource = ExamResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var Exam $exam */
        $exam = $this->getRecord();

        app(ExamSectionSync::class)->handle($exam);
    }
}
