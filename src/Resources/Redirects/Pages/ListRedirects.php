<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Resources\Redirects\Pages;

use Asignua\FilamentRedirects\Redirects;
use Asignua\FilamentRedirects\Resources\Redirects\RedirectResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListRedirects extends ListRecords
{
    protected static string $resource = RedirectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('flush_cache')
                ->label(__('filament-redirects::redirects.actions.flush_cache'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function (): void {
                    Redirects::flushCache();

                    Notification::make()
                        ->title(__('filament-redirects::redirects.actions.cache_flushed'))
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
