<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\TelegramFlowResource\Pages;

use App\Domain\Telegram\Actions\PublishFlow;
use App\Domain\Telegram\Enums\PublishStatus;
use App\Domain\Telegram\Models\TelegramFlow;
use App\Filament\Academy\Resources\TelegramFlowResource;
use App\Filament\Support\FlowVersioning;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

final class EditTelegramFlow extends EditRecord
{
    protected static string $resource = TelegramFlowResource::class;

    /**
     * @return array<int, Actions\Action|Actions\ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('publish')
                ->label(__('panel.flows.action.publish'))
                ->icon('heroicon-o-rocket-launch')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => auth()->user()?->can('telegram.flow.publish') === true
                    && ! $this->flow()->is_active)
                ->action(fn () => $this->publish()),

            Actions\Action::make('newVersion')
                ->label(__('panel.flows.action.new_version'))
                ->icon('heroicon-o-document-duplicate')
                ->visible(fn (): bool => auth()->user()?->can('telegram.flow.update') === true
                    && $this->flow()->status === PublishStatus::Published)
                ->action(function () {
                    $draft = app(FlowVersioning::class)->draftFrom($this->flow());

                    Notification::make()->success()->title(__('panel.flows.notify.drafted'))->send();

                    return redirect(TelegramFlowResource::getUrl('edit', ['record' => $draft]));
                }),

            Actions\Action::make('rollback')
                ->label(__('panel.flows.action.rollback'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription(__('panel.flows.help.rollback'))
                ->visible(fn (): bool => auth()->user()?->can('telegram.flow.publish') === true
                    && $this->flow()->status !== PublishStatus::Draft
                    && ! $this->flow()->is_active)
                ->action(fn () => $this->publish()),

            Actions\Action::make('unpublish')
                ->label(__('panel.flows.action.unpublish'))
                ->icon('heroicon-o-pause-circle')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (): bool => auth()->user()?->can('telegram.flow.publish') === true
                    && $this->flow()->is_active)
                ->action(function (): void {
                    app(PublishFlow::class)->unpublish($this->flow());

                    Notification::make()->success()->title(__('panel.flows.notify.unpublished'))->send();

                    $this->refreshFormData(['status', 'is_active']);
                }),

            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Validation first, so a cycle or dangling edge lands as a form error the
     * author can fix — never as PublishFlow's RuntimeException.
     */
    private function publish(): void
    {
        $flow = $this->flow();
        $publisher = app(PublishFlow::class);

        $errors = $publisher->validate($flow);

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->addError('data.nodes', $error);
            }

            Notification::make()
                ->danger()
                ->title(__('panel.flows.notify.publish_failed'))
                ->body(implode("\n", $errors))
                ->persistent()
                ->send();

            return;
        }

        $publisher->handle($flow, auth()->id());

        Notification::make()->success()->title(__('panel.flows.notify.published'))->send();

        $this->refreshFormData(['status', 'is_active']);
    }

    private function flow(): TelegramFlow
    {
        /** @var TelegramFlow $record */
        $record = $this->getRecord();

        return $record;
    }
}
