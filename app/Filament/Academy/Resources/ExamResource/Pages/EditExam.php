<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\ExamResource\Pages;

use App\Domain\Assessment\Actions\PublishExam;
use App\Domain\Assessment\Enums\ExamStatus;
use App\Domain\Assessment\Models\Exam;
use App\Filament\Academy\Resources\ExamResource;
use App\Filament\Academy\Support\ExamSectionSync;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Throwable;

final class EditExam extends EditRecord
{
    protected static string $resource = ExamResource::class;

    /**
     * @return array<int, Actions\Action|Actions\ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('publish')
                ->label(__('panel.exams.action.publish'))
                ->icon('heroicon-o-rocket-launch')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => auth()->user()?->can('exams.publish') === true
                    && $this->getRecord()->status !== ExamStatus::Published)
                ->action(function (): void {
                    /** @var Exam $exam */
                    $exam = $this->getRecord();

                    app(ExamSectionSync::class)->handle($exam);

                    try {
                        app(PublishExam::class)->handle($exam);

                        Notification::make()->success()->title(__('panel.exams.notify.published'))->send();
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title(__('panel.exams.notify.publish_failed'))->body($e->getMessage())->send();
                    }
                }),
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        /** @var Exam $exam */
        $exam = $this->getRecord();

        app(ExamSectionSync::class)->handle($exam);
    }
}
