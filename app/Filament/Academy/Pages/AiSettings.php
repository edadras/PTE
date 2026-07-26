<?php

declare(strict_types=1);

namespace App\Filament\Academy\Pages;

use App\Domain\AI\Enums\AiProvider;
use App\Domain\AI\Enums\AiTaskKey;
use App\Domain\AI\Models\AcademyAiSetting;
use App\Domain\AI\Models\AiModel;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;

/**
 * Provider, model and guardrails per task key (docs/06 §2).
 *
 * The BYOK field is write-only: it is never hydrated from the record, and an
 * empty submission leaves the stored key untouched. The panel only ever shows
 * the last four characters, which the model maintains itself.
 */
final class AiSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.academy.pages.ai-settings';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.ai');
    }

    public static function getNavigationLabel(): string
    {
        return __('panel.ai.title');
    }

    public function getTitle(): string
    {
        return __('panel.ai.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('ai.settings.view') === true;
    }

    public function mount(): void
    {
        $this->form->fill($this->currentState());
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\Tabs::make('tasks')
                    ->columnSpanFull()
                    ->tabs(array_map(
                        fn (AiTaskKey $task): Forms\Components\Tabs\Tab => Forms\Components\Tabs\Tab::make($task->label())
                            ->schema($this->taskSchema($task)),
                        AiTaskKey::cases(),
                    )),
            ]);
    }

    /**
     * @return array<int, Component>
     */
    private function taskSchema(AiTaskKey $task): array
    {
        $path = 'settings.'.str_replace('.', '__', $task->value);

        return [
            Forms\Components\Select::make($path.'.provider')
                ->label(__('panel.ai.field.provider'))
                ->options(fn (): array => collect($task->isTranscription()
                    ? AiProvider::transcriptionProviders()
                    : AiProvider::completionProviders())
                    ->mapWithKeys(fn (AiProvider $p): array => [$p->value => $p->label()])
                    ->all())
                ->live()
                ->required(),

            Forms\Components\Select::make($path.'.model_key')
                ->label(__('panel.ai.field.model'))
                ->options(fn (Get $get): array => self::modelOptions($get($path.'.provider')))
                ->required(),

            Forms\Components\TextInput::make($path.'.temperature')
                ->label(__('panel.ai.field.temperature'))
                ->numeric()->step('0.1')->minValue(0)->maxValue(2)
                ->default((float) config('pte.ai.default_temperature', 0.3)),

            Forms\Components\TextInput::make($path.'.max_output_tokens')
                ->label(__('panel.ai.field.max_output_tokens'))
                ->numeric()
                ->default((int) config('pte.ai.default_max_output_tokens', 1200)),

            Forms\Components\Select::make($path.'.fallback_chain')
                ->label(__('panel.ai.field.fallback_chain'))
                ->multiple()
                ->options(fn (): array => self::allModelOptions())
                ->helperText(__('panel.ai.help.fallback_chain'))
                ->columnSpanFull(),

            Forms\Components\Toggle::make($path.'.cache_enabled')
                ->label(__('panel.ai.field.cache_enabled'))
                ->default(true),

            Forms\Components\Toggle::make($path.'.economy_mode')
                ->label(__('panel.ai.field.economy_mode'))
                ->helperText(__('panel.ai.help.economy_mode')),

            Forms\Components\Toggle::make($path.'.use_own_key')
                ->label(__('panel.ai.field.use_own_key'))
                ->helperText(__('panel.ai.help.byok'))
                ->live(),

            Forms\Components\TextInput::make($path.'.api_key')
                ->label(__('panel.ai.field.api_key'))
                ->password()
                ->revealable()
                ->autocomplete(false)
                // Never hydrated: the field starts empty on every load and an
                // empty submission means "leave the stored key alone".
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->helperText(fn () => $this->keyHint($task))
                ->visible(fn (Get $get): bool => (bool) $get($path.'.use_own_key'))
                ->columnSpanFull(),
        ];
    }

    public function save(): void
    {
        Gate::authorize('ai.settings.update');

        /** @var array<string, array<string, mixed>> $settings */
        $settings = $this->form->getState()['settings'] ?? [];

        foreach ($settings as $encodedKey => $values) {
            $task = AiTaskKey::tryFrom(str_replace('__', '.', $encodedKey));

            if ($task === null || blank($values['model_key'] ?? null)) {
                continue;
            }

            $attributes = [
                'provider' => $values['provider'],
                'model_key' => $values['model_key'],
                'temperature' => $values['temperature'] ?? null,
                'max_output_tokens' => $values['max_output_tokens'] ?? null,
                'fallback_chain' => $values['fallback_chain'] ?? [],
                'cache_enabled' => (bool) ($values['cache_enabled'] ?? true),
                'economy_mode' => (bool) ($values['economy_mode'] ?? false),
                'use_own_key' => (bool) ($values['use_own_key'] ?? false),
            ];

            if (filled($values['api_key'] ?? null)) {
                $attributes['api_key'] = $values['api_key'];
            }

            AcademyAiSetting::query()->updateOrCreate(
                ['task_key' => $task->value],
                $attributes,
            );
        }

        Notification::make()->success()->title(__('panel.ai.saved'))->send();

        $this->form->fill($this->currentState());
    }

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getFormActions(): array
    {
        return [
            \Filament\Actions\Action::make('save')
                ->label(__('panel.common.save'))
                ->submit('save')
                ->visible(fn (): bool => auth()->user()?->can('ai.settings.update') === true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentState(): array
    {
        $rows = AcademyAiSetting::query()->get()->keyBy(
            static fn (AcademyAiSetting $row): string => $row->task_key->value
        );

        $settings = [];

        foreach (AiTaskKey::cases() as $task) {
            $row = $rows->get($task->value);
            $default = AiModel::defaultFor($task);

            $settings[str_replace('.', '__', $task->value)] = [
                'provider' => $row?->provider?->value ?? $default?->provider->value,
                'model_key' => $row?->model_key ?? $default?->model_key,
                'temperature' => $row?->temperature ?? (float) config('pte.ai.default_temperature', 0.3),
                'max_output_tokens' => $row?->max_output_tokens ?? (int) config('pte.ai.default_max_output_tokens', 1200),
                'fallback_chain' => $row?->fallback_chain ?? [],
                'cache_enabled' => $row?->cache_enabled ?? true,
                'economy_mode' => $row?->economy_mode ?? false,
                'use_own_key' => $row?->use_own_key ?? false,
                // Deliberately absent: the stored key is never sent to the browser.
                'api_key' => null,
            ];
        }

        return ['settings' => $settings];
    }

    private function keyHint(AiTaskKey $task): string
    {
        $row = AcademyAiSetting::query()->forTask($task)->first();

        return filled($row?->api_key_last4)
            ? __('panel.ai.key_stored', ['last4' => (string) $row->api_key_last4])
            : __('panel.ai.key_absent');
    }

    /**
     * @return array<string, string>
     */
    private static function modelOptions(mixed $provider): array
    {
        $provider = $provider instanceof AiProvider ? $provider : AiProvider::tryFrom((string) $provider);

        return AiModel::query()
            ->active()
            ->when($provider !== null, fn ($query) => $query->where('provider', $provider->value))
            ->orderBy('sort_order')
            ->pluck('display_name', 'model_key')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function allModelOptions(): array
    {
        return AiModel::query()->active()->orderBy('sort_order')->pluck('display_name', 'model_key')->all();
    }
}
