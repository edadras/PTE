<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\QuestionResource\Pages;

use App\Domain\Learning\Actions\UpdateQuestion;
use App\Domain\Learning\Exceptions\InvalidQuestionContentException;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionOption;
use App\Filament\Academy\Resources\QuestionResource;
use App\Filament\Support\QuestionContentSchema;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class EditQuestion extends EditRecord
{
    protected static string $resource = QuestionResource::class;

    /**
     * @return array<int, Actions\Action|Actions\ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Question $record */
        $record = $this->getRecord();

        $data['options'] = $record->options
            ->map(static fn (QuestionOption $option): array => [
                'key' => $option->option_key,
                'text' => $option->text,
                'is_correct' => $option->is_correct,
                'explanation' => $option->explanation,
            ])
            ->all();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Question $record */
        $attributes = [
            'title' => $data['title'] ?? null,
            'content' => QuestionContentSchema::prune(
                $record->type,
                is_array($data['content'] ?? null) ? $data['content'] : [],
            ),
            'tags' => $data['tags'] ?? [],
            'difficulty' => $data['difficulty'] ?? $record->difficulty,
            'bank_id' => isset($data['bank_id']) ? (int) $data['bank_id'] : $record->bank_id,
        ];

        if (is_array($data['options'] ?? null)) {
            $attributes['options'] = array_values($data['options']);
        }

        try {
            return app(UpdateQuestion::class)->handle($record, $attributes);
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
