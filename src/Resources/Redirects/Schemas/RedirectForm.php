<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Resources\Redirects\Schemas;

use Asignua\FilamentRedirects\Enums\RedirectCode;
use Asignua\FilamentRedirects\Forms\RedirectPathField;
use Asignua\FilamentRedirects\Redirects;
use Asignua\FilamentRedirects\Support\LanguageNames;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class RedirectForm
{
    public static function configure(Schema $schema): Schema
    {
        $urls = Redirects::localeUrls();

        return $schema->components([
            Section::make(__('filament-redirects::redirects.fields.general'))
                ->columns(2)
                ->schema([
                    RedirectPathField::oldPath(),

                    // A single-language site has nothing to choose: the select is not rendered
                    // and the repository falls back to the default language.
                    Select::make('language')
                        ->label(__('filament-redirects::redirects.fields.language'))
                        ->options(LanguageNames::options())
                        ->default($urls->default())
                        // The language drives the prefix affix of both path fields.
                        ->live()
                        ->required()
                        ->visible(fn (): bool => count(Redirects::localeUrls()->all()) > 1),

                    Select::make('code')
                        ->label(__('filament-redirects::redirects.fields.code'))
                        ->options(RedirectCode::options())
                        ->default(RedirectCode::Permanent->value)
                        ->live()
                        ->required(),

                    Toggle::make('active')
                        ->label(__('filament-redirects::redirects.fields.active'))
                        ->default(true),

                    RedirectPathField::toPath()
                        ->columnSpanFull()
                        ->hidden(fn (Get $get): bool => (int) $get('code') === RedirectCode::Gone->value)
                        ->requiredUnless('code', (string) RedirectCode::Gone->value),
                ])
                ->columnSpanFull(),
        ]);
    }
}
