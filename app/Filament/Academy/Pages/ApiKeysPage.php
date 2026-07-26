<?php

declare(strict_types=1);

namespace App\Filament\Academy\Pages;

use App\Domain\Identity\Models\ApiKey;
use App\Filament\Support\ApiScopes;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * REST API credentials (docs/08 §2).
 *
 * The plaintext exists only in `$revealedKey` — a Livewire property of *this*
 * page instance, shown right after issuing. It is never persisted, never
 * hydrated into a form, and gone the moment the page is opened again; the
 * database only ever holds the SHA-256 hash.
 */
final class ApiKeysPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?int $navigationSort = 40;

    protected static string $view = 'filament.academy.pages.api-keys';

    protected static ?string $slug = 'api-keys';

    public ?string $revealedKey = null;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('panel.api_keys.title');
    }

    public function getTitle(): string
    {
        return __('panel.api_keys.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('api_keys.manage') === true;
    }

    /**
     * @return Collection<int, ApiKey>
     */
    public function getKeys(): Collection
    {
        return ApiKey::query()->orderByDesc('id')->get();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('issue')
                ->label(__('panel.api_keys.action.issue'))
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => auth()->user()?->can('api_keys.manage') === true)
                ->form([
                    Forms\Components\TextInput::make('name')
                        ->label(__('panel.api_keys.field.name'))
                        ->required()
                        ->maxLength(100),
                    Forms\Components\CheckboxList::make('scopes')
                        ->label(__('panel.api_keys.field.scopes'))
                        ->options(ApiScopes::options())
                        ->helperText(__('panel.api_keys.help.scopes'))
                        ->columns(2),
                ])
                ->action(function (array $data): void {
                    Gate::authorize('api_keys.manage');

                    $user = auth()->user();

                    $issued = ApiKey::issue(
                        (string) $data['name'],
                        array_values(array_map(strval(...), (array) ($data['scopes'] ?? []))),
                        $user instanceof User ? $user : null,
                    );

                    // Shown once, on this render only.
                    $this->revealedKey = $issued['plain_text'];

                    Notification::make()
                        ->success()
                        ->title(__('panel.api_keys.notify.issued'))
                        ->body(__('panel.api_keys.notify.issued_body'))
                        ->persistent()
                        ->send();
                }),
        ];
    }

    public function revoke(int $keyId): void
    {
        Gate::authorize('api_keys.manage');

        $key = ApiKey::query()->find($keyId);

        if (! $key instanceof ApiKey || $key->revoked_at !== null) {
            return;
        }

        $key->forceFill(['revoked_at' => now()])->save();

        Notification::make()->success()->title(__('panel.api_keys.notify.revoked'))->send();
    }

    public function dismissRevealedKey(): void
    {
        $this->revealedKey = null;
    }
}
