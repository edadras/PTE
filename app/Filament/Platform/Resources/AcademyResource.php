<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Commerce\Models\Plan;
use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Learning\Support\ModuleRegistry;
use App\Domain\Tenancy\Actions\CloneAcademy;
use App\Domain\Tenancy\Actions\ResumeAcademy;
use App\Domain\Tenancy\Actions\SuspendAcademy;
use App\Domain\Tenancy\Data\CreateAcademyData;
use App\Domain\Tenancy\Enums\AcademyStatus;
use App\Domain\Tenancy\Models\Academy;
use App\Filament\Platform\Resources\AcademyResource\Pages;
use App\Filament\Support\Impersonation;
use App\Filament\Support\PlatformAudit;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The tenant list: who exists, on what plan, how many learners, and what they
 * cost us in AI this month.
 *
 * @see docs/01-multi-tenancy.md §8 · docs/06-ai-layer.md §7.4
 */
final class AcademyResource extends Resource
{
    protected static ?string $model = Academy::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.tenants');
    }

    public static function getModelLabel(): string
    {
        return __('panel.academies.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.academies.plural');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('platform.academies.manage') === true;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('panel.academies.section.identity'))
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label(__('panel.academies.field.name'))
                        ->required()
                        ->maxLength(150),
                    Forms\Components\TextInput::make('slug')
                        ->label(__('panel.academies.field.slug'))
                        ->helperText(__('panel.academies.help.slug'))
                        ->maxLength(60)
                        ->disabledOn('edit'),
                    Forms\Components\TextInput::make('legal_name')
                        ->label(__('panel.academies.field.legal_name'))
                        ->maxLength(200),
                    Forms\Components\Select::make('status')
                        ->label(__('panel.academies.field.status'))
                        ->options(AcademyStatus::options())
                        ->disabledOn('create')
                        ->default(AcademyStatus::Active->value),
                ])
                ->columns(2),

            Forms\Components\Section::make(__('panel.academies.section.owner'))
                ->schema([
                    Forms\Components\TextInput::make('owner_email')
                        ->label(__('panel.academies.field.owner_email'))
                        ->email()
                        ->dehydrated()
                        ->maxLength(200),
                    Forms\Components\TextInput::make('owner_name')
                        ->label(__('panel.academies.field.owner_name'))
                        ->maxLength(150),
                ])
                ->columns(2)
                ->visibleOn('create'),

            Forms\Components\Section::make(__('panel.academies.section.commercial'))
                ->schema([
                    Forms\Components\Select::make('plan_id')
                        ->label(__('panel.academies.field.plan'))
                        ->options(fn (): array => Plan::query()->orderBy('sort_order')->pluck('name', 'id')->all())
                        ->searchable(),
                    Forms\Components\TextInput::make('timezone')
                        ->label(__('panel.academies.field.timezone'))
                        ->default('Asia/Tehran')
                        ->maxLength(64),
                    Forms\Components\TextInput::make('country')
                        ->label(__('panel.academies.field.country'))
                        ->default('IR')
                        ->maxLength(2),
                    Forms\Components\Select::make('locale')
                        ->label(__('panel.academies.field.locale'))
                        ->options(['fa' => 'فارسی', 'en' => 'English'])
                        ->default('fa')
                        ->visibleOn('create')
                        ->dehydrated(),
                ])
                ->columns(2),

            Forms\Components\Section::make(__('panel.academies.section.modules'))
                ->schema([
                    Forms\Components\CheckboxList::make('modules')
                        ->label(__('panel.academies.field.modules'))
                        ->options(fn (): array => collect(ModuleRegistry::available())
                            ->mapWithKeys(fn (ModuleKey $module): array => [
                                $module->value => $module->icon().' '.$module->label(),
                            ])
                            ->all())
                        ->columns(2)
                        ->dehydrated(),
                ])
                ->visibleOn('create'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('panel.academies.field.name'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Academy $record): string => $record->slug),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('panel.academies.field.status'))
                    ->badge()
                    ->formatStateUsing(fn (AcademyStatus $state): string => $state->label())
                    ->color(fn (AcademyStatus $state): string => $state->color()),

                Tables\Columns\TextColumn::make('plan_name')
                    ->label(__('panel.academies.field.plan'))
                    ->badge()
                    ->default('—'),

                Tables\Columns\TextColumn::make('students_count')
                    ->label(__('panel.academies.field.students'))
                    ->counts('students')
                    ->sortable(),

                Tables\Columns\TextColumn::make('ai_spend_cents')
                    ->label(__('panel.academies.field.ai_spend'))
                    ->formatStateUsing(fn (mixed $state): string => '$'.number_format(((float) $state) / 100, 2))
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('panel.common.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('panel.academies.field.status'))
                    ->options(AcademyStatus::options()),
                Tables\Filters\SelectFilter::make('plan_id')
                    ->label(__('panel.academies.field.plan'))
                    ->options(fn (): array => Plan::query()->pluck('name', 'id')->all()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('suspend')
                    ->label(__('panel.academies.action.suspend'))
                    ->icon('heroicon-o-pause-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (Academy $record): bool => $record->status === AcademyStatus::Active)
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label(__('panel.academies.field.suspension_reason'))
                            ->required(),
                    ])
                    ->action(function (Academy $record, array $data): void {
                        app(SuspendAcademy::class)->handle($record, (string) $data['reason']);

                        PlatformAudit::record(
                            action: AuditAction::AcademySuspended,
                            actor: auth()->user() instanceof User ? auth()->user() : null,
                            target: $record,
                            payload: ['reason' => $data['reason']],
                        );

                        Notification::make()->success()->title(__('panel.academies.notify.suspended'))->send();
                    }),

                Tables\Actions\Action::make('resume')
                    ->label(__('panel.academies.action.resume'))
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Academy $record): bool => $record->status === AcademyStatus::Suspended)
                    ->action(function (Academy $record): void {
                        app(ResumeAcademy::class)->handle($record);

                        PlatformAudit::record(
                            action: AuditAction::AcademyResumed,
                            actor: auth()->user() instanceof User ? auth()->user() : null,
                            target: $record,
                        );

                        Notification::make()->success()->title(__('panel.academies.notify.resumed'))->send();
                    }),

                Tables\Actions\Action::make('clone')
                    ->label(__('panel.academies.action.clone'))
                    ->icon('heroicon-o-document-duplicate')
                    ->form([
                        Forms\Components\TextInput::make('name')
                            ->label(__('panel.academies.field.name'))
                            ->required(),
                        Forms\Components\TextInput::make('slug')
                            ->label(__('panel.academies.field.slug')),
                        Forms\Components\TextInput::make('owner_email')
                            ->label(__('panel.academies.field.owner_email'))
                            ->email(),
                    ])
                    ->action(function (Academy $record, array $data): void {
                        $clone = app(CloneAcademy::class)->handle(
                            $record,
                            CreateAcademyData::fromArray($data),
                        );

                        PlatformAudit::record(
                            action: AuditAction::AcademyCloned,
                            actor: auth()->user() instanceof User ? auth()->user() : null,
                            target: $clone,
                            payload: ['source_academy_id' => $record->getKey()],
                        );

                        Notification::make()->success()->title(__('panel.academies.notify.cloned'))->send();
                    }),

                Tables\Actions\Action::make('impersonate')
                    ->label(__('panel.academies.action.impersonate'))
                    ->icon('heroicon-o-identification')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (): string => __('panel.impersonation.confirm'))
                    ->visible(fn (): bool => auth()->user()?->can('platform.impersonate') === true)
                    ->action(function (Academy $record) {
                        $actor = auth()->user();

                        if (! $actor instanceof User) {
                            return null;
                        }

                        try {
                            return redirect()->away(Impersonation::handoverUrl($record, $actor));
                        } catch (Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title(__('panel.impersonation.failed'))
                                ->body($e->getMessage())
                                ->send();

                            return null;
                        }
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        $period = now()->startOfMonth();

        // `academies` has a plan_id column but no Eloquent relation (Tenancy must
        // not depend on Commerce), so the name is pulled with a subquery.
        return parent::getEloquentQuery()
            ->addSelect([
                'ai_spend_cents' => DB::table('ai_requests')
                    ->selectRaw('COALESCE(SUM(cost_usd) * 100, 0)')
                    ->whereColumn('ai_requests.academy_id', 'academies.id')
                    ->where('ai_requests.created_at', '>=', $period),
                'plan_name' => DB::table('plans')
                    ->select('name')
                    ->whereColumn('plans.id', 'academies.plan_id')
                    ->limit(1),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAcademies::route('/'),
            'create' => Pages\CreateAcademy::route('/create'),
            'edit' => Pages\EditAcademy::route('/{record}/edit'),
        ];
    }
}
