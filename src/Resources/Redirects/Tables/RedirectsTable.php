<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Resources\Redirects\Tables;

use Asignua\FilamentRedirects\Enums\RedirectCode;
use Asignua\FilamentRedirects\Models\Redirect;
use Asignua\FilamentRedirects\Redirects;
use Asignua\FilamentRedirects\Repositories\RedirectRepository;
use Asignua\FilamentRedirects\Support\LanguageNames;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class RedirectsTable
{
    public static function configure(Table $table): Table
    {
        $multilingual = fn (): bool => count(Redirects::localeUrls()->all()) > 1;

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('entity'))
            ->columns([
                TextColumn::make('old_path')
                    ->label(__('filament-redirects::redirects.fields.old_path'))
                    ->formatStateUsing(fn (string $state): string => '/'.$state)
                    ->searchable()
                    ->wrap()
                    ->sortable(),
                TextColumn::make('to_path')
                    ->label(__('filament-redirects::redirects.fields.new_path'))
                    ->formatStateUsing(fn (string $state): string => str_contains($state, '://') || str_starts_with($state, '/') ? $state : '/'.$state)
                    ->searchable()
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('language')
                    ->label(__('filament-redirects::redirects.fields.language'))
                    ->badge()
                    ->visible($multilingual),
                TextColumn::make('code')
                    ->label(__('filament-redirects::redirects.fields.code'))
                    ->badge()
                    ->formatStateUsing(fn (RedirectCode $state): string => $state->label())
                    ->color(fn (RedirectCode $state): string => $state->color()),
                IconColumn::make('active')
                    ->label(__('filament-redirects::redirects.fields.active'))
                    ->boolean(),
                TextColumn::make('hits')
                    ->label(__('filament-redirects::redirects.fields.hits'))
                    ->alignEnd()
                    ->numeric()
                    ->sortable(),
                TextColumn::make('last_hit_at')
                    ->label(__('filament-redirects::redirects.fields.last_hit'))
                    ->since()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('entity_type')
                    ->label(__('filament-redirects::redirects.fields.destination'))
                    ->state(fn (Redirect $record): ?string => self::destinationLabel($record->entity))
                    ->url(fn (Redirect $record): ?string => self::destinationUrl($record->entity))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('language')
                    ->label(__('filament-redirects::redirects.fields.language'))
                    ->options(LanguageNames::options())
                    ->visible($multilingual),
                SelectFilter::make('code')
                    ->label(__('filament-redirects::redirects.fields.code'))
                    ->options(RedirectCode::options()),
                TernaryFilter::make('active')
                    ->label(__('filament-redirects::redirects.fields.active')),
                TernaryFilter::make('hit')
                    ->label(__('filament-redirects::redirects.fields.hits'))
                    ->trueLabel(__('filament-redirects::redirects.filters.used'))
                    ->falseLabel(__('filament-redirects::redirects.filters.unused'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('hits', '>', 0),
                        false: fn (Builder $query): Builder => $query->where('hits', 0),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('activate')
                        ->label(__('filament-redirects::redirects.actions.activate'))
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->action(fn (Collection $records) => self::setActive($records, true))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deactivate')
                        ->label(__('filament-redirects::redirects.actions.deactivate'))
                        ->icon(Heroicon::OutlinedNoSymbol)
                        ->action(fn (Collection $records) => self::setActive($records, false))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * @param Collection<int, Model> $records
     */
    private static function setActive(Collection $records, bool $active): void
    {
        $skipped = 0;

        foreach ($records as $record) {
            if (!$record instanceof Redirect) {
                continue;
            }

            try {
                app(RedirectRepository::class)->update($record, ['active' => $active]);
            } catch (ValidationException) {
                // Activating it would close a loop with the redirects that are already active:
                // it stays inactive, the others go on.
                $skipped++;
            }
        }

        if ($skipped > 0) {
            Notification::make()
                ->title(__('filament-redirects::redirects.actions.activate_skipped', ['count' => $skipped]))
                ->warning()
                ->send();
        }
    }

    private static function destinationLabel(?Model $entity): ?string
    {
        return $entity === null ? null : class_basename($entity).' #'.$entity->getKey();
    }

    private static function destinationUrl(?Model $entity): ?string
    {
        if ($entity === null) {
            return null;
        }

        $resource = Filament::getModelResource($entity::class);

        return $resource !== null && $resource::hasPage('edit') && $resource::canEdit($entity)
            ? $resource::getUrl('edit', ['record' => $entity])
            : null;
    }
}
