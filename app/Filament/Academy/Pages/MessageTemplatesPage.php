<?php

declare(strict_types=1);

namespace App\Filament\Academy\Pages;

use App\Domain\Tenancy\Data\PlaceholderContext;
use App\Domain\Tenancy\Models\MessageTemplate;
use App\Domain\Tenancy\Services\MessageTemplateResolver;
use App\Domain\Tenancy\Services\PlaceholderRenderer;
use App\Domain\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;

/**
 * Per-academy overrides of the platform message copy (docs/03 §6).
 *
 * The preview renders through the same PlaceholderRenderer + resolver chain
 * the bot uses, so what is shown here is what will be sent; "reset" removes
 * the override so future improvements to the platform default reach this
 * academy again.
 */
final class MessageTemplatesPage extends Page
{
    use InteractsWithFormActions;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static ?int $navigationSort = 15;

    protected static string $view = 'filament.academy.pages.message-templates';

    protected static ?string $slug = 'message-templates';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('panel.templates.title');
    }

    public function getTitle(): string
    {
        return __('panel.templates.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('academy.brand.view') === true;
    }

    public function mount(): void
    {
        $key = MessageTemplate::KEYS[0];
        $locale = TenantContext::require()->preferredLocale() ?? 'fa';

        $this->form->fill([
            'key' => $key,
            'locale' => $locale,
            'channel' => MessageTemplate::CHANNEL_TELEGRAM,
            'content' => $this->customContent($key, $locale, MessageTemplate::CHANNEL_TELEGRAM),
        ]);
    }

    public function form(Form $form): Form
    {
        $reload = function (Get $get, Set $set): void {
            $set('content', $this->customContent(
                (string) $get('key'),
                (string) $get('locale'),
                (string) $get('channel'),
            ));
        };

        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\Section::make(__('panel.templates.section.select'))
                    ->schema([
                        Forms\Components\Select::make('key')
                            ->label(__('panel.templates.field.key'))
                            ->options(self::keyOptions())
                            ->required()
                            ->live()
                            ->afterStateUpdated($reload),
                        Forms\Components\Select::make('locale')
                            ->label(__('panel.templates.field.locale'))
                            ->options(['fa' => 'فارسی', 'en' => 'English', 'ar' => 'العربية', 'tr' => 'Türkçe'])
                            ->required()
                            ->live()
                            ->afterStateUpdated($reload),
                        Forms\Components\Select::make('channel')
                            ->label(__('panel.templates.field.channel'))
                            ->options([
                                MessageTemplate::CHANNEL_TELEGRAM => __('panel.templates.channel.telegram'),
                                MessageTemplate::CHANNEL_EMAIL => __('panel.templates.channel.email'),
                                MessageTemplate::CHANNEL_SMS => __('panel.templates.channel.sms'),
                            ])
                            ->required()
                            ->live()
                            ->afterStateUpdated($reload),
                    ])
                    ->columns(3),

                Forms\Components\Section::make(__('panel.templates.section.edit'))
                    ->schema([
                        Forms\Components\Placeholder::make('platform_default')
                            ->label(__('panel.templates.field.default'))
                            ->content(fn (Get $get): string => $this->platformDefault($get) === ''
                                ? __('panel.templates.no_default')
                                : $this->platformDefault($get)),
                        Forms\Components\Textarea::make('content')
                            ->label(__('panel.templates.field.content'))
                            ->rows(6)
                            ->live(onBlur: true)
                            ->helperText(__('panel.templates.help.content')),
                        Forms\Components\Placeholder::make('placeholders')
                            ->label(__('panel.templates.field.placeholders'))
                            ->content(fn () => view('filament.academy.forms.placeholder-list', [
                                'groups' => PlaceholderRenderer::GROUPS,
                            ])),
                        Forms\Components\Placeholder::make('preview')
                            ->label(__('panel.templates.field.preview'))
                            ->content(fn (Get $get) => view('filament.academy.forms.bot-message-preview', [
                                'welcome' => $this->renderPreview($get),
                                'footer' => '',
                                'unknown' => $this->unknownPlaceholders($get),
                                'name' => TenantContext::require()->name,
                            ])),
                    ]),
            ]);
    }

    public function save(): void
    {
        Gate::authorize('academy.brand.update');

        $state = $this->form->getState();
        $content = trim((string) ($state['content'] ?? ''));

        MessageTemplate::query()->updateOrCreate(
            [
                'key' => (string) $state['key'],
                'locale' => (string) $state['locale'],
                'channel' => (string) $state['channel'],
            ],
            [
                'content' => $content === '' ? null : $content,
                'is_customized' => $content !== '',
            ],
        );

        Notification::make()->success()->title(__('panel.templates.notify.saved'))->send();
    }

    /**
     * @return array<int, Action>
     */
    public function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('panel.common.save'))
                ->submit('save')
                ->visible(fn (): bool => auth()->user()?->can('academy.brand.update') === true),

            Action::make('reset')
                ->label(__('panel.templates.action.reset'))
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('panel.templates.help.reset'))
                ->visible(fn (): bool => auth()->user()?->can('academy.brand.update') === true
                    && $this->currentIsCustomized())
                ->action(function (): void {
                    Gate::authorize('academy.brand.update');

                    $state = $this->form->getRawState();

                    MessageTemplate::query()
                        ->forKey((string) $state['key'], (string) $state['locale'], (string) $state['channel'])
                        ->delete();

                    $this->data['content'] = null;

                    Notification::make()->success()->title(__('panel.templates.notify.reset'))->send();
                }),
        ];
    }

    public function currentIsCustomized(): bool
    {
        $state = $this->form->getRawState();

        return app(MessageTemplateResolver::class)->isCustomized(
            (string) ($state['key'] ?? ''),
            (string) ($state['locale'] ?? 'fa'),
            (string) ($state['channel'] ?? MessageTemplate::CHANNEL_TELEGRAM),
        );
    }

    private function customContent(string $key, string $locale, string $channel): ?string
    {
        return MessageTemplate::query()
            ->forKey($key, $locale, $channel)
            ->customized()
            ->first()
            ?->content;
    }

    private function platformDefault(Get $get): string
    {
        return app(MessageTemplateResolver::class)->platformDefault(
            (string) $get('key'),
            (string) $get('locale'),
        );
    }

    private function renderPreview(Get $get): string
    {
        $content = trim((string) $get('content'));

        if ($content === '') {
            $content = $this->platformDefault($get);
        }

        return app(PlaceholderRenderer::class)->renderRaw($content, $this->sampleContext());
    }

    /**
     * @return array<int, string>
     */
    private function unknownPlaceholders(Get $get): array
    {
        return app(PlaceholderRenderer::class)->unknown(
            (string) $get('content'),
            $this->sampleContext(),
        );
    }

    private function sampleContext(): PlaceholderContext
    {
        return PlaceholderContext::make([
            'first_name' => __('panel.brand.sample.first_name'),
            'last_name' => __('panel.brand.sample.last_name'),
            'full_name' => __('panel.brand.sample.full_name'),
            'student_code' => 'ST123456',
            'level' => 'intermediate',
            'total_practices' => 42,
            'avg_score' => 68,
            'streak_days' => 5,
            'last_score' => 71,
            'plan_name' => __('billing.plan.professional'),
            'days_remaining' => 12,
            'expires_at' => now()->addDays(12)->format('Y/m/d'),
        ])
            ->forAcademy(TenantContext::require())
            ->withMoment();
    }

    /**
     * @return array<string, string>
     */
    private static function keyOptions(): array
    {
        $options = [];

        foreach (MessageTemplate::KEYS as $key) {
            $options[$key] = __('panel.templates.keys.'.$key);
        }

        return $options;
    }
}
