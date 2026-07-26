<?php

declare(strict_types=1);

namespace App\Filament\Academy\Pages;

use App\Domain\Telegram\Actions\ConnectBot;
use App\Domain\Telegram\Actions\RotateBotToken;
use App\Domain\Telegram\Jobs\RegisterWebhook;
use App\Domain\Telegram\Models\TelegramBot;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Bot connection (docs/04 §1).
 *
 * The token is write-only in every direction: the model hides and encrypts it,
 * and this page never puts it in form state — only `••••••` plus the last four
 * characters is ever rendered.
 */
final class TelegramBotSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.academy.pages.telegram-bot';

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.telegram');
    }

    public static function getNavigationLabel(): string
    {
        return __('panel.telegram.title');
    }

    public function getTitle(): string
    {
        return __('panel.telegram.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('telegram.bot.view') === true;
    }

    public function getBot(): ?TelegramBot
    {
        return TelegramBot::query()->first();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->connectAction(),
            $this->rotateAction(),
            $this->reregisterAction(),
        ];
    }

    private function connectAction(): Action
    {
        return Action::make('connect')
            ->label(fn (): string => $this->getBot() === null
                ? __('panel.telegram.action.connect')
                : __('panel.telegram.action.reconnect'))
            ->icon('heroicon-o-link')
            ->color('primary')
            ->visible(fn (): bool => auth()->user()?->can('telegram.bot.update') === true)
            ->form([
                Forms\Components\TextInput::make('token')
                    ->label(__('panel.telegram.field.token'))
                    ->required()
                    ->password()
                    ->revealable()
                    ->autocomplete(false)
                    ->helperText(__('panel.telegram.help.token')),
                Forms\Components\TextInput::make('notify_chat_id')
                    ->label(__('panel.telegram.field.notify_chat_id'))
                    ->numeric()
                    ->helperText(__('panel.telegram.help.notify_chat_id')),
            ])
            ->action(function (array $data): void {
                Gate::authorize('telegram.bot.update');

                try {
                    $bot = app(ConnectBot::class)->handle(
                        (string) $data['token'],
                        $data['notify_chat_id'] === null ? null : (int) $data['notify_chat_id'],
                    );

                    Notification::make()
                        ->success()
                        ->title(__('panel.telegram.notify.connected', ['username' => (string) $bot->username]))
                        ->body(__('panel.telegram.notify.webhook_queued'))
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(__('panel.telegram.notify.connect_failed'))->body($e->getMessage())->send();
                }
            });
    }

    private function rotateAction(): Action
    {
        return Action::make('rotate')
            ->label(__('panel.telegram.action.rotate'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription(fn (): string => __('panel.telegram.help.rotate'))
            ->visible(fn (): bool => auth()->user()?->can('telegram.bot.update') === true && $this->getBot() !== null)
            ->form([
                Forms\Components\TextInput::make('token')
                    ->label(__('panel.telegram.field.new_token'))
                    ->required()
                    ->password()
                    ->revealable()
                    ->autocomplete(false),
            ])
            ->action(function (array $data): void {
                Gate::authorize('telegram.bot.update');

                $bot = $this->getBot();

                if ($bot === null) {
                    return;
                }

                try {
                    $identity = app(RotateBotToken::class)->handle($bot, (string) $data['token']);

                    Notification::make()
                        ->success()
                        ->title(__('panel.telegram.notify.rotated', ['username' => (string) $identity->username]))
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(__('panel.telegram.notify.rotate_failed'))->body($e->getMessage())->send();
                }
            });
    }

    private function reregisterAction(): Action
    {
        return Action::make('reregister')
            ->label(__('panel.telegram.action.reregister'))
            ->icon('heroicon-o-bolt')
            ->requiresConfirmation()
            ->visible(fn (): bool => auth()->user()?->can('telegram.bot.update') === true && $this->getBot() !== null)
            ->action(function (): void {
                Gate::authorize('telegram.bot.update');

                $bot = $this->getBot();

                if ($bot === null) {
                    return;
                }

                RegisterWebhook::dispatch((int) $bot->academy_id, (int) $bot->getKey(), false);

                Notification::make()->success()->title(__('panel.telegram.notify.webhook_queued'))->send();
            });
    }
}
