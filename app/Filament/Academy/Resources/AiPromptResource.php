<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources;

use App\Domain\AI\Enums\AiTaskKey;
use App\Domain\AI\Enums\PromptStatus;
use App\Domain\AI\Models\AiPrompt;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Academy\Resources\AiPromptResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The prompt builder of docs/06 §3: system prompt, user template, the variable
 * list for the chosen task, a mandatory test run, publish, and rollback.
 */
final class AiPromptResource extends Resource
{
    protected static ?string $model = AiPrompt::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.ai');
    }

    public static function getModelLabel(): string
    {
        return __('panel.prompts.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.prompts.plural');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('ai.prompts.view') === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('ai.prompts.update') === true;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('ai.prompts.update') === true
            && $record instanceof AiPrompt
            && ! $record->isPlatformDefault();
    }

    public static function canDelete(Model $record): bool
    {
        return self::canEdit($record);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('panel.prompts.section.target'))
                ->schema([
                    Forms\Components\Select::make('key')
                        ->label(__('panel.prompts.field.key'))
                        ->options(fn (): array => collect(AiTaskKey::promptedTasks())
                            ->mapWithKeys(fn (AiTaskKey $t): array => [$t->value => $t->label()])
                            ->all())
                        ->required()
                        ->live()
                        ->disabledOn('edit'),
                    Forms\Components\TextInput::make('model_hint')
                        ->label(__('panel.prompts.field.model_hint'))
                        ->maxLength(120),
                    Forms\Components\Placeholder::make('variables_hint')
                        ->label(__('panel.prompts.field.variables'))
                        ->content(fn (Get $get): string => implode(' · ', array_map(
                            static fn (string $variable): string => '{{'.$variable.'}}',
                            self::variablesFor($get('key')),
                        )))
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Forms\Components\Section::make(__('panel.prompts.section.template'))
                ->schema([
                    Forms\Components\Textarea::make('system_prompt')
                        ->label(__('panel.prompts.field.system_prompt'))
                        ->rows(6)
                        ->helperText(__('panel.prompts.help.system_prompt'))
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make('user_template')
                        ->label(__('panel.prompts.field.user_template'))
                        ->rows(14)
                        ->required()
                        ->columnSpanFull(),
                    Forms\Components\KeyValue::make('output_schema')
                        ->label(__('panel.prompts.field.output_schema'))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * @return array<int, string>
     */
    public static function variablesFor(mixed $key): array
    {
        $task = $key instanceof AiTaskKey ? $key : AiTaskKey::tryFrom((string) $key);

        return $task?->availableVariables() ?? [];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('key')
                    ->label(__('panel.prompts.field.key'))
                    ->formatStateUsing(fn (AiTaskKey $state): string => $state->label())
                    ->description(fn (AiPrompt $record): string => $record->key->value)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('version')->label(__('panel.prompts.field.version'))->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('panel.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (PromptStatus $state): string => $state->label())
                    ->color(fn (PromptStatus $state): string => match ($state) {
                        PromptStatus::Published => 'success',
                        PromptStatus::Archived => 'gray',
                        PromptStatus::Draft => 'warning',
                    }),
                Tables\Columns\IconColumn::make('academy_id')
                    ->label(__('panel.prompts.field.owner'))
                    ->icon(fn (?int $state): string => $state === null ? 'heroicon-o-globe-alt' : 'heroicon-o-building-office')
                    ->tooltip(fn (?int $state): string => $state === null
                        ? __('panel.prompts.platform_default')
                        : __('panel.prompts.academy_owned')),
                Tables\Columns\TextColumn::make('tested_at')->label(__('panel.prompts.field.tested_at'))->dateTime()->placeholder('—'),
                Tables\Columns\TextColumn::make('published_at')->label(__('panel.prompts.field.published_at'))->dateTime()->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('key')
                    ->label(__('panel.prompts.field.key'))
                    ->options(fn (): array => collect(AiTaskKey::promptedTasks())
                        ->mapWithKeys(fn (AiTaskKey $t): array => [$t->value => $t->label()])
                        ->all()),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('panel.common.status'))
                    ->options(fn (): array => collect(PromptStatus::cases())
                        ->mapWithKeys(fn (PromptStatus $s): array => [$s->value => $s->label()])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ReplicateAction::make()
                    ->label(__('panel.prompts.action.fork'))
                    ->icon('heroicon-o-document-duplicate')
                    ->visible(fn (): bool => auth()->user()?->can('ai.prompts.update') === true)
                    ->beforeReplicaSaved(function (AiPrompt $replica, AiPrompt $record): void {
                        $replica->academy_id = TenantContext::id();
                        $replica->status = PromptStatus::Draft;
                        $replica->version = $record->nextVersion();
                        $replica->tested_at = null;
                        $replica->test_results = null;
                        $replica->published_at = null;
                        $replica->created_by = auth()->id();
                    }),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * A prompt is either the academy's or the platform's default; the model's
     * own scope expresses that, so no manual where is needed.
     */
    public static function getEloquentQuery(): Builder
    {
        return AiPrompt::query()->visibleTo(TenantContext::id());
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiPrompts::route('/'),
            'create' => Pages\CreateAiPrompt::route('/create'),
            'edit' => Pages\EditAiPrompt::route('/{record}/edit'),
        ];
    }
}
