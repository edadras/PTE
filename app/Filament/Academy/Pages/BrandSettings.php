<?php

declare(strict_types=1);

namespace App\Filament\Academy\Pages;

use App\Domain\Tenancy\Data\PlaceholderContext;
use App\Domain\Tenancy\Enums\DarkMode;
use App\Domain\Tenancy\Models\AcademyBrand;
use App\Domain\Tenancy\Services\BrandResolver;
use App\Domain\Tenancy\Services\PlaceholderRenderer;
use App\Domain\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;

/**
 * Everything a student ever sees (docs/03).
 *
 * The welcome/footer preview renders through the same PlaceholderRenderer the
 * bot uses, so what is shown here is what will be sent.
 */
final class BrandSettings extends Page
{
    use InteractsWithFormActions;

    protected static ?string $navigationIcon = 'heroicon-o-swatch';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.academy.pages.brand-settings';

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
        return __('panel.brand.title');
    }

    public function getTitle(): string
    {
        return __('panel.brand.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('academy.brand.view') === true;
    }

    public function mount(): void
    {
        $this->form->fill($this->brand()->attributesToArray());
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\Tabs::make('brand')
                    ->columnSpanFull()
                    ->tabs([
                        Forms\Components\Tabs\Tab::make(__('panel.brand.tab.identity'))
                            ->schema([
                                Forms\Components\TextInput::make('display_name')
                                    ->label(__('panel.brand.field.display_name'))->required()->maxLength(100),
                                Forms\Components\TextInput::make('short_name')
                                    ->label(__('panel.brand.field.short_name'))->maxLength(40),
                                Forms\Components\TextInput::make('tagline')
                                    ->label(__('panel.brand.field.tagline'))->maxLength(160)->columnSpanFull(),
                                Forms\Components\FileUpload::make('logo_light_path')
                                    ->label(__('panel.brand.field.logo_light'))
                                    ->disk('tenant')->directory('brand')->visibility('private')
                                    ->image()->maxSize(2048),
                                Forms\Components\FileUpload::make('logo_dark_path')
                                    ->label(__('panel.brand.field.logo_dark'))
                                    ->disk('tenant')->directory('brand')->visibility('private')
                                    ->image()->maxSize(2048),
                                Forms\Components\FileUpload::make('icon_path')
                                    ->label(__('panel.brand.field.icon'))
                                    ->disk('tenant')->directory('brand')->visibility('private')
                                    ->image()->maxSize(1024),
                                Forms\Components\FileUpload::make('welcome_image_path')
                                    ->label(__('panel.brand.field.welcome_image'))
                                    ->disk('tenant')->directory('brand')->visibility('private')
                                    ->image()->maxSize(5120),
                            ])
                            ->columns(2),

                        Forms\Components\Tabs\Tab::make(__('panel.brand.tab.look'))
                            ->schema([
                                Forms\Components\ColorPicker::make('primary_color')->label(__('panel.brand.field.primary_color'))->required(),
                                Forms\Components\ColorPicker::make('secondary_color')->label(__('panel.brand.field.secondary_color'))->required(),
                                Forms\Components\ColorPicker::make('accent_color')->label(__('panel.brand.field.accent_color')),
                                Forms\Components\ColorPicker::make('success_color')->label(__('panel.brand.field.success_color')),
                                Forms\Components\ColorPicker::make('danger_color')->label(__('panel.brand.field.danger_color')),
                                Forms\Components\Select::make('dark_mode')
                                    ->label(__('panel.brand.field.dark_mode'))
                                    ->options(fn (): array => collect(DarkMode::cases())
                                        ->mapWithKeys(fn (DarkMode $m): array => [$m->value => $m->label()])
                                        ->all())
                                    ->default(DarkMode::Auto->value),
                                Forms\Components\Select::make('font_family')
                                    ->label(__('panel.brand.field.font_family'))
                                    ->options([
                                        'Vazirmatn' => 'Vazirmatn',
                                        'IRANSans' => 'IRANSans',
                                        'Inter' => 'Inter',
                                    ])
                                    ->default('Vazirmatn'),
                            ])
                            ->columns(3),

                        Forms\Components\Tabs\Tab::make(__('panel.brand.tab.copy'))
                            ->schema([
                                Forms\Components\Textarea::make('welcome_text')
                                    ->label(__('panel.brand.field.welcome_text'))
                                    ->rows(5)
                                    ->live(onBlur: true)
                                    ->helperText(__('panel.brand.help.placeholders'))
                                    ->columnSpanFull(),
                                Forms\Components\Textarea::make('footer_text')
                                    ->label(__('panel.brand.field.footer_text'))
                                    ->rows(3)
                                    ->live(onBlur: true)
                                    ->columnSpanFull(),
                                Forms\Components\Placeholder::make('preview')
                                    ->label(__('panel.brand.preview'))
                                    ->content(fn (Get $get) => view('filament.academy.forms.bot-message-preview', [
                                        'welcome' => $this->renderPlaceholders((string) $get('welcome_text')),
                                        'footer' => $this->renderPlaceholders((string) $get('footer_text')),
                                        'unknown' => $this->unknownPlaceholders(
                                            (string) $get('welcome_text').' '.(string) $get('footer_text')
                                        ),
                                        'name' => (string) ($get('display_name') ?? ''),
                                    ]))
                                    ->columnSpanFull(),
                            ]),

                        Forms\Components\Tabs\Tab::make(__('panel.brand.tab.contact'))
                            ->schema([
                                Forms\Components\TextInput::make('website_url')->label(__('panel.brand.field.website'))->url(),
                                Forms\Components\TextInput::make('instagram_url')->label(__('panel.brand.field.instagram'))->url(),
                                Forms\Components\TextInput::make('telegram_channel')->label(__('panel.brand.field.telegram_channel')),
                                Forms\Components\TextInput::make('whatsapp')->label(__('panel.brand.field.whatsapp')),
                                Forms\Components\TextInput::make('support_phone')->label(__('panel.brand.field.support_phone')),
                                Forms\Components\TextInput::make('support_email')->label(__('panel.brand.field.support_email'))->email(),
                                Forms\Components\Textarea::make('address')->label(__('panel.brand.field.address'))->rows(2),
                                Forms\Components\Textarea::make('working_hours')->label(__('panel.brand.field.working_hours'))->rows(2),
                                Forms\Components\TextInput::make('terms_url')->label(__('panel.brand.field.terms'))->url(),
                                Forms\Components\TextInput::make('privacy_url')->label(__('panel.brand.field.privacy'))->url(),
                            ])
                            ->columns(2),

                        Forms\Components\Tabs\Tab::make(__('panel.brand.tab.locales'))
                            ->schema([
                                Forms\Components\Select::make('default_locale')
                                    ->label(__('panel.brand.field.default_locale'))
                                    ->options(self::localeOptions())
                                    ->required(),
                                Forms\Components\CheckboxList::make('supported_locales')
                                    ->label(__('panel.brand.field.supported_locales'))
                                    ->options(self::localeOptions())
                                    ->columns(2),
                            ])
                            ->columns(2),
                    ]),
            ]);
    }

    public function save(): void
    {
        Gate::authorize('academy.brand.update');

        $brand = $this->brand();
        $brand->fill($this->form->getState());
        $brand->save();

        app(BrandResolver::class)->forget(TenantContext::require());

        Notification::make()->success()->title(__('panel.brand.saved'))->send();
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
        ];
    }

    private function brand(): AcademyBrand
    {
        $academy = TenantContext::require();

        /** @var AcademyBrand $brand */
        $brand = AcademyBrand::query()->firstOrCreate(
            ['academy_id' => $academy->getKey()],
            ['display_name' => $academy->name],
        );

        return $brand;
    }

    private function renderPlaceholders(string $template): string
    {
        return app(PlaceholderRenderer::class)
            ->renderRaw($template, $this->sampleContext());
    }

    /**
     * @return array<int, string>
     */
    private function unknownPlaceholders(string $template): array
    {
        return app(PlaceholderRenderer::class)
            ->unknown($template, $this->sampleContext());
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
        ])
            ->forAcademy(TenantContext::require())
            ->withMoment();
    }

    /**
     * @return array<string, string>
     */
    private static function localeOptions(): array
    {
        return ['fa' => 'فارسی', 'en' => 'English', 'ar' => 'العربية', 'tr' => 'Türkçe'];
    }
}
