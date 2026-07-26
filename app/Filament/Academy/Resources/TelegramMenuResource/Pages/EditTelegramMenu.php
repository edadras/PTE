<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\TelegramMenuResource\Pages;

use App\Domain\Telegram\Actions\PublishMenu;
use App\Domain\Telegram\Enums\PublishStatus;
use App\Domain\Telegram\Models\TelegramMenu;
use App\Filament\Academy\Resources\TelegramMenuResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Throwable;

final class EditTelegramMenu extends EditRecord
{
    protected static string $resource = TelegramMenuResource::class;

    /**
     * @return array<int, Actions\Action|Actions\ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('publish')
                ->label(__('panel.menus.action.publish'))
                ->icon('heroicon-o-rocket-launch')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => auth()->user()?->can('telegram.menu.update') === true)
                ->action(function (): void {
                    /** @var TelegramMenu $menu */
                    $menu = $this->getRecord();

                    try {
                        app(PublishMenu::class)->handle($menu, auth()->id());

                        Notification::make()->success()->title(__('panel.menus.notify.published'))->send();
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title(__('panel.menus.notify.publish_failed'))->body($e->getMessage())->send();
                    }
                }),

            Actions\Action::make('draft')
                ->label(__('panel.menus.action.new_draft'))
                ->icon('heroicon-o-document-duplicate')
                ->visible(fn (): bool => auth()->user()?->can('telegram.menu.update') === true
                    && $this->getRecord()->status === PublishStatus::Published)
                ->action(function () {
                    /** @var TelegramMenu $menu */
                    $menu = $this->getRecord();

                    $draft = app(PublishMenu::class)->draftFrom($menu);

                    Notification::make()->success()->title(__('panel.menus.notify.drafted'))->send();

                    return redirect(TelegramMenuResource::getUrl('edit', ['record' => $draft]));
                }),

            Actions\Action::make('rollback')
                ->label(__('panel.menus.action.rollback'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (): bool => auth()->user()?->can('telegram.menu.update') === true
                    && $this->getRecord()->status === PublishStatus::Published
                    && ! $this->getRecord()->is_active)
                ->action(function (): void {
                    /** @var TelegramMenu $menu */
                    $menu = $this->getRecord();

                    try {
                        app(PublishMenu::class)->rollbackTo($menu);

                        Notification::make()->success()->title(__('panel.menus.notify.rolled_back'))->send();
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title(__('panel.menus.notify.publish_failed'))->body($e->getMessage())->send();
                    }
                }),

            Actions\DeleteAction::make(),
        ];
    }
}
