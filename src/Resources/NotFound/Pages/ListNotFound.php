<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Resources\NotFound\Pages;

use Asignua\FilamentRedirects\Repositories\NotFoundRepository;
use Asignua\FilamentRedirects\Resources\NotFound\NotFoundResource;
use Asignua\FilamentRedirects\Support\NotFoundRecheck;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListNotFound extends ListRecords
{
    protected static string $resource = NotFoundResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Prunes BY STATE: closes the rows where a visitor now gets a page or a redirect.
            Action::make('recheck')
                ->label(__('filament-redirects::redirects.actions.recheck'))
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('gray')
                ->action(function (): void {
                    $result = app(NotFoundRecheck::class)->run();

                    Notification::make()
                        ->title(__('filament-redirects::redirects.actions.recheck_done', [
                            'deleted' => $result['deleted'],
                            'checked' => $result['checked'],
                        ]))
                        ->success()
                        ->send();
                }),

            // Prunes BY AGE, with the same horizon as `redirects:prune`.
            Action::make('prune')
                ->label(__('filament-redirects::redirects.actions.prune'))
                ->icon(Heroicon::OutlinedTrash)
                ->color('gray')
                ->requiresConfirmation()
                ->action(function (): void {
                    $days = (int) config('filament-redirects.not_found.retention_days', 90);
                    $deleted = app(NotFoundRepository::class)->prune($days);

                    Notification::make()
                        ->title(__('filament-redirects::redirects.actions.pruned', ['count' => $deleted, 'days' => $days]))
                        ->success()
                        ->send();
                }),
        ];
    }
}
