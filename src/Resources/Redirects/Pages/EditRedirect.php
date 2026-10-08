<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Resources\Redirects\Pages;

use Asignua\FilamentRedirects\Models\Redirect;
use Asignua\FilamentRedirects\Repositories\RedirectRepository;
use Asignua\FilamentRedirects\Resources\Redirects\RedirectResource;
use Asignua\FilamentRedirects\Support\RedirectPath;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditRedirect extends EditRecord
{
    protected static string $resource = RedirectResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Redirect $record */
        // An untouched source stays as stored (see RedirectPath::unchanged()).
        if (array_key_exists('old_path', $data) && RedirectPath::unchanged((string) $data['old_path'], $record->old_path)) {
            unset($data['old_path']);
        }

        return app(RedirectRepository::class)->update($record, $data);
    }
}
