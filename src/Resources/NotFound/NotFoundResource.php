<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Resources\NotFound;

use Asignua\FilamentRedirects\Repositories\NotFoundRepository;
use Asignua\FilamentRedirects\Resources\Concerns\ReadsPluginNavigation;
use Asignua\FilamentRedirects\Resources\NotFound\Pages\ListNotFound;
use Asignua\FilamentRedirects\Resources\NotFound\Tables\NotFoundTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The 404 log of the site: the unmatched paths, and a one-click way to turn one into a
 * redirect (the row disappears from the log afterwards - from then on the redirect counts the
 * hits). The rows are written by the fallback middleware, never by the panel.
 */
class NotFoundResource extends Resource
{
    use ReadsPluginNavigation;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static int $sortOffset = 1;

    protected static ?string $slug = 'not-found';

    protected static ?string $recordTitleAttribute = 'path';

    // The log has no view or edit page: a global-search hit would lead to a dead
    // `?tableAction=view` URL, and scanner paths would flood the panel's search.
    protected static bool $isGloballySearchable = false;

    public static function getModel(): string
    {
        return app(NotFoundRepository::class)->modelClass();
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-redirects::redirects.not_found.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('filament-redirects::redirects.not_found.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-redirects::redirects.not_found.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return NotFoundTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotFound::route('/'),
        ];
    }
}
