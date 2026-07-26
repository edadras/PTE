<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\QuestionResource\Pages;

use App\Domain\Learning\Actions\ImportQuestions;
use App\Domain\Learning\Enums\QuestionStatus;
use App\Domain\Learning\Models\QuestionBank;
use App\Filament\Academy\Resources\QuestionResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ListQuestions extends ListRecords
{
    protected static string $resource = QuestionResource::class;

    /**
     * @return array<int, Actions\Action|Actions\ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            $this->importAction(),
        ];
    }

    /**
     * CSV import is entirely the Learning context's ImportQuestions action; this
     * only hands it a path and renders the report it returns.
     */
    private function importAction(): Actions\Action
    {
        return Actions\Action::make('import')
            ->label(__('panel.questions.action.import'))
            ->icon('heroicon-o-arrow-up-tray')
            ->visible(fn (): bool => auth()->user()?->can('questions.import') === true)
            ->form([
                Forms\Components\Select::make('bank_id')
                    ->label(__('panel.question_banks.singular'))
                    ->options(fn (): array => QuestionBank::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->required(),
                Forms\Components\FileUpload::make('file')
                    ->label(__('panel.questions.field.csv'))
                    ->disk('local')
                    ->directory('imports')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv'])
                    ->required(),
                Forms\Components\Toggle::make('dry_run')
                    ->label(__('panel.questions.field.dry_run'))
                    ->default(true),
                Forms\Components\Toggle::make('all_or_nothing')
                    ->label(__('panel.questions.field.all_or_nothing'))
                    ->default(true),
            ])
            ->action(function (array $data): void {
                $bank = QuestionBank::query()->findOrFail((int) $data['bank_id']);
                $path = Storage::disk('local')->path((string) $data['file']);

                try {
                    $importer = app(ImportQuestions::class);

                    $report = $data['dry_run']
                        ? $importer->dryRun($path, $bank)
                        : $importer->handle($path, $bank, [
                            'all_or_nothing' => (bool) $data['all_or_nothing'],
                            'status' => QuestionStatus::Draft,
                            'created_by' => auth()->id(),
                        ]);

                    Notification::make()
                        ->status($report->isClean() ? 'success' : 'warning')
                        ->title(__('panel.questions.notify.imported', [
                            'imported' => $report->imported,
                            'failed' => $report->failedCount(),
                        ]))
                        ->body(implode("\n", array_slice($report->errorLines(), 0, 10)) ?: null)
                        ->persistent()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(__('panel.questions.notify.import_failed'))->body($e->getMessage())->send();
                } finally {
                    Storage::disk('local')->delete((string) $data['file']);
                }
            });
    }
}
