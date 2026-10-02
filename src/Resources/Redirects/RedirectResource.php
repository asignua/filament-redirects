<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Resources\Redirects;

use Asignua\FilamentRedirects\RedirectsPlugin;
use Asignua\FilamentRedirects\Repositories\RedirectRepository;
use Asignua\FilamentRedirects\Resources\Concerns\ReadsPluginNavigation;
use Asignua\FilamentRedirects\Resources\Redirects\Pages\CreateRedirect;
use Asignua\FilamentRedirects\Resources\Redirects\Pages\EditRedirect;
use Asignua\FilamentRedirects\Resources\Redirects\Pages\ListRedirects;
use Asignua\FilamentRedirects\Resources\Redirects\Schemas\RedirectForm;
use Asignua\FilamentRedirects\Resources\Redirects\Tables\RedirectsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RedirectResource extends Resource
{
    use ReadsPluginNavigation;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static int $sortOffset = 0;

    protected static ?string $recordTitleAttribute = 'old_path';

    public static function getModel(): string
    {
        return app(RedirectRepository::class)->modelClass();
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        $plugin = filament()->hasPlugin(RedirectsPlugin::ID) ? RedirectsPlugin::get() : null;

        return $plugin?->getNavigationIcon() ?? static::$navigationIcon;
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-redirects::redirects.redirects.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('filament-redirects::redirects.redirects.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-redirects::redirects.redirects.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return RedirectForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RedirectsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRedirects::route('/'),
            'create' => CreateRedirect::route('/create'),
            'edit' => EditRedirect::route('/{record}/edit'),
        ];
    }
}
