<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Resources\NotFound\Tables;

use Asignua\FilamentRedirects\Enums\RedirectCode;
use Asignua\FilamentRedirects\Forms\RedirectPathField;
use Asignua\FilamentRedirects\Models\NotFoundEntry;
use Asignua\FilamentRedirects\Redirects;
use Asignua\FilamentRedirects\Repositories\RedirectRepository;
use Asignua\FilamentRedirects\Support\LanguageNames;
use Asignua\FilamentRedirects\Support\RedirectPath;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class NotFoundTable
{
    public static function configure(Table $table): Table
    {
        $multilingual = fn (): bool => count(Redirects::localeUrls()->all()) > 1;

        // The status hook is asked ONCE per row: the badge needs both the label and the colour.
        $statuses = [];
        $status = static function (NotFoundEntry $record) use (&$statuses): ?array {
            $key = (string) $record->getKey();

            if (!array_key_exists($key, $statuses)) {
                $statuses[$key] = Redirects::statusFor($record->path, $record->language);
            }

            return $statuses[$key];
        };

        return $table
            ->columns([
                // The path is stored WITHOUT the language prefix, so the link is built the way
                // the site builds its addresses - otherwise an `en` row would lead to the
                // default-language version of the address.
                TextColumn::make('path')
                    ->label(__('filament-redirects::redirects.fields.path'))
                    ->formatStateUsing(fn (string $state): string => '/'.$state)
                    ->searchable()
                    ->wrap()
                    ->sortable()
                    // No link without a base URL: `''.'/'.$path` with a logged `\evil.example` would be
                    // `/\evil.example`, which a browser reads as `//evil.example`.
                    ->url(fn (NotFoundEntry $record): ?string => Redirects::baseUrl() === ''
                        ? null
                        : Redirects::localeUrls()->url($record->language, $record->path, true))
                    ->openUrlInNewTab()
                    ->tooltip(__('filament-redirects::redirects.actions.open_on_site')),
                TextColumn::make('language')
                    ->label(__('filament-redirects::redirects.fields.language'))
                    ->badge()
                    ->visible($multilingual),
                TextColumn::make('status')
                    ->label(__('filament-redirects::redirects.fields.status'))
                    ->badge()
                    ->state(fn (NotFoundEntry $record): ?string => $status($record)['label'] ?? null)
                    ->color(fn (NotFoundEntry $record): string => $status($record)['color'] ?? 'gray')
                    ->placeholder('—')
                    ->visible(fn (): bool => Redirects::hasStatusHook()),
                TextColumn::make('hits')
                    ->label(__('filament-redirects::redirects.fields.hits'))
                    ->alignEnd()
                    ->numeric()
                    ->sortable(),
                TextColumn::make('referrer')
                    ->label(__('filament-redirects::redirects.fields.referrer'))
                    ->limit(48)
                    ->tooltip(fn (NotFoundEntry $record): ?string => $record->referrer)
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('user_agent')
                    ->label(__('filament-redirects::redirects.fields.user_agent'))
                    ->badge()
                    ->color(fn (NotFoundEntry $record): string => $record->is_bot ? 'warning' : 'gray')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(__('filament-redirects::redirects.fields.first_seen'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_seen_at')
                    ->label(__('filament-redirects::redirects.fields.last_seen'))
                    ->since()
                    ->sortable(),
                IconColumn::make('is_bot')
                    ->label(__('filament-redirects::redirects.fields.bot'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('language')
                    ->label(__('filament-redirects::redirects.fields.language'))
                    ->options(LanguageNames::options())
                    ->visible($multilingual),
                TernaryFilter::make('is_bot')
                    ->label(__('filament-redirects::redirects.filters.bot'))
                    ->trueLabel(__('filament-redirects::redirects.filters.only_bots'))
                    ->falseLabel(__('filament-redirects::redirects.filters.no_bots')),
                TernaryFilter::make('has_referrer')
                    ->label(__('filament-redirects::redirects.filters.referrer'))
                    ->trueLabel(__('filament-redirects::redirects.filters.with_referrer'))
                    ->falseLabel(__('filament-redirects::redirects.filters.without_referrer'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('referrer'),
                        false: fn (Builder $query): Builder => $query->whereNull('referrer'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::createRedirectAction(),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::createRedirectsAction(),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('hits', 'desc');
    }

    /**
     * Creates a redirect straight from a log row: `old_path` and the language come from the
     * row, the write goes through the repository (entity binding + loop guard - a loop is
     * raised on the `to_path` field of the modal). The log row is deleted: from now on the
     * redirect counts the hits.
     */
    private static function createRedirectAction(): Action
    {
        return Action::make('create_redirect')
            ->label(__('filament-redirects::redirects.actions.create_redirect'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->modalHeading(__('filament-redirects::redirects.actions.create_redirect'))
            ->modalDescription(fn (NotFoundEntry $record): string => $record->language.' | /'.$record->path)
            // The schema is a closure: the language and `old_path` come from the ROW, and both
            // the prefix affix of the field and the self-loop check depend on them.
            ->schema(fn (NotFoundEntry $record): array => [
                RedirectPathField::toPath(
                    languageResolver: static fn (): string => $record->language,
                    oldPathResolver: static fn (): string => $record->path,
                )->required(),

                Select::make('code')
                    ->label(__('filament-redirects::redirects.fields.code'))
                    ->options(RedirectCode::options())
                    ->default(RedirectCode::Permanent->value)
                    ->required(),

                Toggle::make('active')
                    ->label(__('filament-redirects::redirects.fields.active'))
                    ->default(true),
            ])
            ->fillForm(fn (NotFoundEntry $record): array => [
                'to_path' => RedirectPath::display(Redirects::suggestionFor($record->language, $record->path)),
                'code' => RedirectCode::Permanent->value,
                'active' => true,
            ])
            ->action(function (NotFoundEntry $record, array $data, Action $action): void {
                $repository = app(RedirectRepository::class);

                if ($repository->oldPathTaken($record->language, RedirectPath::normalize($record->path, $record->language), null)) {
                    // A redirect already exists (made in another tab): the row is stale.
                    $record->delete();

                    Notification::make()
                        ->title(__('filament-redirects::redirects.validation.taken'))
                        ->warning()
                        ->send();

                    return;
                }

                try {
                    $repository->create([
                        // The log stores the DECODED path: escape it, so a real `what?`
                        // (requested as `/what%3F`) is not read as a query string.
                        'old_path' => RedirectPath::escape($record->path),
                        'to_path' => (string) $data['to_path'],
                        'language' => $record->language,
                        'code' => (int) $data['code'],
                        'active' => (bool) ($data['active'] ?? true),
                    ]);
                } catch (ValidationException $exception) {
                    // The repository keys its errors for the resource pages (`data.to_path`),
                    // which match no field of this modal: say it out loud and keep the modal open.
                    // (A loop is normally caught by the field rule; this is the race backstop.)
                    self::refused($exception);
                    $action->halt();

                    return;
                }

                $record->delete();

                Notification::make()
                    ->title(__('filament-redirects::redirects.actions.redirect_created'))
                    ->success()
                    ->send();
            });
    }

    /**
     * One target for many paths ("send all of these to the shop"). A row whose redirect would
     * be a loop, or whose path already has a redirect, is reported and skipped; the others
     * disappear from the log.
     */
    private static function createRedirectsAction(): BulkAction
    {
        return BulkAction::make('create_redirects')
            ->label(__('filament-redirects::redirects.actions.create_redirects'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->modalHeading(__('filament-redirects::redirects.actions.create_redirects'))
            ->schema([
                RedirectPathField::toPath(
                    languageResolver: static fn (): string => Redirects::localeUrls()->default(),
                    oldPathResolver: static fn (): string => '',
                )->required(),

                Select::make('code')
                    ->label(__('filament-redirects::redirects.fields.code'))
                    ->options(RedirectCode::options())
                    ->default(RedirectCode::Permanent->value)
                    ->required(),
            ])
            ->action(function (Collection $records, array $data): void {
                $repository = app(RedirectRepository::class);
                $created = 0;
                $skipped = 0;

                foreach ($records as $record) {
                    if (!$record instanceof NotFoundEntry) {
                        continue;
                    }

                    $old = RedirectPath::normalize($record->path, $record->language);

                    if ($repository->oldPathTaken($record->language, $old, null)) {
                        $record->delete(); // already redirected: the row is stale, not a failure
                        $skipped++;

                        continue;
                    }

                    try {
                        $repository->create([
                            // The log stores the DECODED path: escape it, so a real `what?`
                            // (requested as `/what%3F`) is not read as a query string.
                            'old_path' => RedirectPath::escape($record->path),
                            'to_path' => (string) $data['to_path'],
                            'language' => $record->language,
                            'code' => (int) $data['code'],
                            'active' => true,
                        ]);
                    } catch (ValidationException) {
                        $skipped++; // refused by the repository (a loop: the target is the path itself or leads back to it)

                        continue;
                    }

                    $record->delete();
                    $created++;
                }

                $notification = Notification::make()
                    ->title(__('filament-redirects::redirects.actions.redirects_created', ['count' => $created]));

                if ($skipped > 0) {
                    $notification->body(__('filament-redirects::redirects.actions.redirects_skipped', ['count' => $skipped]))->warning();
                } else {
                    $notification->success();
                }

                $notification->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function refused(ValidationException $exception): void
    {
        Notification::make()
            ->title((string) (collect($exception->errors())->flatten()->first() ?? $exception->getMessage()))
            ->danger()
            ->send();
    }
}
