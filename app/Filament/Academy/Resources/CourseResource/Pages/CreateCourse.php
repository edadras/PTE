<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\CourseResource\Pages;

use App\Filament\Academy\Resources\CourseResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCourse extends CreateRecord
{
    protected static string $resource = CourseResource::class;
}
