<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Resources\Redirects\Pages;

use Asignua\FilamentRedirects\Repositories\RedirectRepository;
use Asignua\FilamentRedirects\Resources\Redirects\RedirectResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRedirect extends CreateRecord
{
    protected static string $resource = RedirectResource::class;

    /**
     * The model has no mass assignment; the repository is the only door for writes.
     *
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(RedirectRepository::class)->create($data);
    }
}
