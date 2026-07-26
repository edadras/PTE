<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\QuestionResource\Pages;

use App\Domain\Learning\Actions\CreateQuestion as CreateQuestionAction;
use App\Domain\Learning\Data\QuestionData;
use App\Domain\Learning\Enums\QuestionStatus;
use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Learning\Exceptions\InvalidQuestionContentException;
use App\Filament\Academy\Resources\QuestionResource;
use App\Filament\Support\QuestionContentSchema;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class CreateQuestion extends CreateRecord
{
    protected static string $resource = QuestionResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $type = QuestionType::from((string) $data['type']);

        $payload = [
            ...$data,
            'content' => QuestionContentSchema::prune($type, is_array($data['content'] ?? null) ? $data['content'] : []),
            'created_by' => auth()->id(),
        ];

        try {
            return app(CreateQuestionAction::class)->handle(
                QuestionData::fromArray($payload),
                QuestionStatus::Draft,
            );
        } catch (InvalidQuestionContentException $e) {
            Notification::make()
                ->danger()
                ->title(__('panel.questions.notify.invalid'))
                ->body(implode("\n", $e->flatErrors()))
                ->send();

            throw ValidationException::withMessages(['content' => $e->flatErrors()]);
        }
    }
}
