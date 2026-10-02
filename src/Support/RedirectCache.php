<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Support;

use Asignua\FilamentRedirects\Models\Redirect;
use Asignua\FilamentRedirects\Repositories\RedirectRepository;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The cached map of the active redirects for the fallback middleware. The key is
 * `"{language}|{old_path}"`. Invalidated by the service provider on every save and delete of a
 * redirect, and by `redirects:flush`.
 */
class RedirectCache
{
    /**
     * @return array<string, array{id: int, to: string, code: int}>
     */
    public function map(): array
    {
        /** @var array<string, array{id: int, to: string, code: int}> */
        return $this->store()->rememberForever($this->key(), fn (): array => $this->build());
    }

    public function flush(): void
    {
        $this->store()->forget($this->key());
    }

    public function key(): string
    {
        return (string) config('filament-redirects.redirects.cache_key', 'filament-redirects.map');
    }

    private function store(): Repository
    {
        $store = config('filament-redirects.redirects.cache_store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }

    /**
     * @return array<string, array{id: int, to: string, code: int}>
     */
    private function build(): array
    {
        $map = [];

        app(RedirectRepository::class)->activeRows()
            ->each(function (Redirect $redirect) use (&$map): void {
                $map[$redirect->language.'|'.$redirect->old_path] = [
                    'id' => $redirect->id,
                    'to' => $redirect->to_path,
                    'code' => $redirect->code->value,
                ];
            });

        return $map;
    }
}
