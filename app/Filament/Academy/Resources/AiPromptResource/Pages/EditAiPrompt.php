<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\AiPromptResource\Pages;

use App\Domain\AI\Enums\PromptStatus;
use App\Domain\AI\Models\AiPrompt;
use App\Filament\Academy\Resources\AiPromptResource;
use App\Filament\Academy\Support\PromptTester;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

final class EditAiPrompt extends EditRecord
{
    protected static string $resource = AiPromptResource::class;

    /**
     * @return array<int, Actions\Action|Actions\ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->testAction(),
            $this->publishAction(),
            $this->rollbackAction(),
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * docs/06 §3 guardrail: a prompt may not be published until it has produced
     * a valid result on real samples.
     */
    private function testAction(): Actions\Action
    {
        return Actions\Action::make('test')
            ->label(__('panel.prompts.action.test'))
            ->icon('heroicon-o-beaker')
            ->color('info')
            ->visible(fn (): bool => auth()->user()?->can('ai.prompts.update') === true)
            ->form([
                Forms\Components\KeyValue::make('variables')
                    ->label(__('panel.prompts.field.sample_variables'))
                    ->keyLabel(__('panel.common.key'))
                    ->valueLabel(__('panel.common.value')),
            ])
            ->action(function (array $data): void {
                /** @var AiPrompt $prompt */
                $prompt = $this->getRecord();

                $result = app(PromptTester::class)->run(
                    $prompt,
                    is_array($data['variables'] ?? null) ? $data['variables'] : [],
                );

                Notification::make()
                    ->status($result['ok'] ? 'success' : 'danger')
                    ->title($result['ok'] ? __('panel.prompts.notify.tested') : __('panel.prompts.notify.test_failed'))
                    ->body($result['message'])
                    ->persistent()
                    ->send();
            });
    }

    private function publishAction(): Actions\Action
    {
        return Actions\Action::make('publish')
            ->label(__('panel.prompts.action.publish'))
            ->icon('heroicon-o-rocket-launch')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (): bool => auth()->user()?->can('ai.prompts.publish') === true
                && $this->getRecord()->status !== PromptStatus::Published)
            ->action(function (): void {
                /** @var AiPrompt $prompt */
                $prompt = $this->getRecord();

                if (! $prompt->isPublishable()) {
                    Notification::make()
                        ->danger()
                        ->title(__('panel.prompts.notify.untested'))
                        ->body(__('panel.prompts.notify.untested_body'))
                        ->send();

                    return;
                }

                app(PromptTester::class)->publish($prompt);

                Notification::make()->success()->title(__('panel.prompts.notify.published'))->send();
            });
    }

    private function rollbackAction(): Actions\Action
    {
        return Actions\Action::make('rollback')
            ->label(__('panel.prompts.action.rollback'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (): bool => auth()->user()?->can('ai.prompts.publish') === true)
            ->form([
                Forms\Components\Select::make('version_id')
                    ->label(__('panel.prompts.field.version'))
                    ->options(fn (): array => app(PromptTester::class)->versionOptions($this->getRecord()))
                    ->required(),
            ])
            ->action(function (array $data): void {
                $restored = app(PromptTester::class)->rollback((int) $data['version_id']);

                Notification::make()
                    ->status($restored ? 'success' : 'danger')
                    ->title($restored ? __('panel.prompts.notify.rolled_back') : __('panel.prompts.notify.publish_failed'))
                    ->send();
            });
    }
}
