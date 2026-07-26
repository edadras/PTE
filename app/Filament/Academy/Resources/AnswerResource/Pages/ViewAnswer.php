<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\AnswerResource\Pages;

use App\Domain\Assessment\Actions\OverrideAnswerScore;
use App\Domain\Assessment\Models\Answer;
use App\Filament\Academy\Resources\AnswerResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Throwable;

final class ViewAnswer extends ViewRecord
{
    protected static string $resource = AnswerResource::class;

    /**
     * @return array<int, Actions\Action|Actions\ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('override')
                ->label(__('panel.answers.action.override'))
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('warning')
                ->visible(fn (): bool => auth()->user()?->can('answers.override_ai_score') === true)
                ->fillForm(fn (): array => ['score' => $this->getRecord()->score])
                ->form([
                    Forms\Components\TextInput::make('score')
                        ->label(__('panel.answers.field.new_score'))
                        ->numeric()
                        ->required()
                        ->minValue(0),
                    Forms\Components\Textarea::make('reason')
                        ->label(__('panel.answers.field.reason'))
                        ->required()
                        ->minLength(5),
                ])
                ->action(function (array $data): void {
                    /** @var Answer $answer */
                    $answer = $this->getRecord();

                    try {
                        app(OverrideAnswerScore::class)->handle(
                            $answer,
                            (float) $data['score'],
                            (string) $data['reason'],
                            (int) auth()->id(),
                        );

                        Notification::make()->success()->title(__('panel.answers.notify.overridden'))->send();

                        $this->refreshFormData(['score', 'override_reason', 'original_ai_score']);
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title(__('panel.answers.notify.override_failed'))->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
