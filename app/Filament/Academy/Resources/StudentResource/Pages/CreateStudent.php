<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\StudentResource\Pages;

use App\Domain\Identity\Actions\CreateStudent as CreateStudentAction;
use App\Domain\Identity\Data\CreateStudentData;
use App\Filament\Academy\Resources\StudentResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateStudent extends CreateRecord
{
    protected static string $resource = StudentResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateStudentAction::class)->handle(CreateStudentData::fromArray($data));
    }
}
