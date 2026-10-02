<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Repositories;

use Asignua\FilamentRedirects\Enums\RedirectCode;
use Asignua\FilamentRedirects\Models\Redirect;
use Asignua\FilamentRedirects\Redirects;
use Asignua\FilamentRedirects\Support\RedirectChain;
use Asignua\FilamentRedirects\Support\RedirectPath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Writes redirects - the ONLY door for writes (the model has `$guarded = ['*']`, so a field
 * that is not assigned here is silently not saved).
 */
class RedirectRepository
{
    /**
     * @return class-string<Redirect>
     */
    public function modelClass(): string
    {
        /** @var class-string<Redirect> */
        return (string) config('filament-redirects.models.redirect', Redirect::class);
    }

    /**
     * @return Builder<Redirect>
     */
    public function query(): Builder
    {
        return $this->modelClass()::query();
    }

    /**
     * Creates a redirect. A given `$entity` is associated BEFORE the save (one save = one cache
     * invalidation, no orphan row in between).
     *
     * @param array<string, mixed> $data `old_path`, `to_path`, `language`, `code`, `active`
     */
    public function create(array $data, ?Model $entity = null): Redirect
    {
        $class = $this->modelClass();
        $redirect = new $class;

        if ($entity !== null) {
            $redirect->entity()->associate($entity);
        }

        return $this->fill($redirect, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(Redirect $redirect, array $data): Redirect
    {
        return $this->fill($redirect, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fill(Redirect $redirect, array $data): Redirect
    {
        // The language goes FIRST: the normalisation of the paths depends on it (a row's own
        // language prefix is stripped). An update without a `language` key keeps the current one.
        if (array_key_exists('language', $data)) {
            $redirect->language = (string) $data['language'];
        }

        $language = (string) ($redirect->language ?: Redirects::localeUrls()->default());
        $redirect->language = $language;

        // The single point of normalisation for every manual entry (the resource form, the
        // "Create redirect" action of the 404 log, `Redirects::create()`): otherwise a host or
        // a language prefix leaks into the column through whichever path forgot to clean it.
        if (array_key_exists('old_path', $data)) {
            $redirect->old_path = RedirectPath::normalize((string) $data['old_path'], $language);
        }

        if (array_key_exists('to_path', $data)) {
            $redirect->to_path = RedirectPath::normalize((string) ($data['to_path'] ?? ''), $language);
        }

        if (array_key_exists('code', $data)) {
            $redirect->code = RedirectCode::from((int) $data['code']);
        }

        if (array_key_exists('active', $data)) {
            $redirect->active = (bool) $data['active'];
        }

        // A new redirect without a type is permanent (the column default, but the model must
        // know it too: it is not refreshed after the insert).
        $code = $redirect->getAttribute('code') ?? RedirectCode::Permanent;
        $redirect->code = $code;

        // A Gone redirect leads nowhere: whatever target was typed is dropped, so the row,
        // the cache and the table never show a target that is not served.
        if (!$code->isRedirect()) {
            $redirect->to_path = '';
        }

        // A target on a foreign site takes part in neither chains nor entity binding.
        $external = RedirectPath::isExternal($redirect->to_path);

        // Loop guard + flattening of the target: only for real redirects.
        $compactable = !$external && $code->isRedirect() && $redirect->to_path !== '';

        if ($compactable) {
            $this->guardAndResolve($redirect);
        }

        // Automatic binding of the destination: only while no entity is set (an explicit
        // association is not overwritten). After flattening, so the FINAL target is linked.
        if (!$external && $redirect->entity_id === null && $redirect->to_path !== '') {
            $entity = Redirects::entityFor($redirect->language, $redirect->to_path);

            if ($entity instanceof Model) {
                $redirect->entity()->associate($entity);
            }
        }

        // Collapse the chains BEFORE the save: the save then invalidates the cache once.
        if ($compactable) {
            $this->compactExisting($redirect);
        }

        $redirect->save();

        return $redirect;
    }

    /**
     * A manual redirect A -> B: reject a loop and flatten the target to the final one along
     * the chain of active redirects of the same language.
     */
    private function guardAndResolve(Redirect $redirect): void
    {
        $map = $this->languageMap($redirect);

        if (RedirectChain::isLoop($map, $redirect->old_path, $redirect->to_path)) {
            throw ValidationException::withMessages([
                'data.to_path' => __('filament-redirects::redirects.validation.loop'),
            ]);
        }

        $redirect->to_path = RedirectChain::resolve($map, $redirect->to_path);
    }

    /**
     * Everything that led to `old_path` now leads straight to the final target.
     */
    private function compactExisting(Redirect $redirect): void
    {
        $query = $this->query()
            ->where('language', $redirect->language)
            ->where('to_path', $redirect->old_path);

        if ($redirect->exists) {
            $query->whereKeyNot($redirect->getKey());
        }

        $query->update([
            'to_path' => $redirect->to_path,
            'entity_type' => $redirect->entity_type,
            'entity_id' => $redirect->entity_id,
        ]);
    }

    /**
     * The active redirects of a language (`old_path => to_path`) without the current row.
     *
     * @return array<string, string>
     */
    private function languageMap(Redirect $redirect): array
    {
        $query = $this->query()
            ->where('language', $redirect->language)
            ->where('active', true);

        if ($redirect->exists) {
            $query->whereKeyNot($redirect->getKey());
        }

        /** @var array<string, string> */
        return $query->pluck('to_path', 'old_path')->all();
    }

    /**
     * Active redirects with only the columns the cache map needs.
     *
     * @return Collection<int, Redirect>
     */
    public function activeRows(): Collection
    {
        return $this->query()
            ->where('active', true)
            ->select(['id', 'language', 'old_path', 'to_path', 'code'])
            ->get();
    }

    /**
     * Counts a hit through the query builder - NO model events, so the cache is not flushed on
     * every visit.
     */
    public function incrementHits(int $id): void
    {
        $this->query()->whereKey($id)->increment('hits', 1, ['last_hit_at' => now()]);
    }

    /**
     * Is `old_path` taken in this language by ANOTHER row? The schema has
     * `unique(old_path, language)`; checking before the save gives a readable message instead
     * of a SQL 23000 error.
     */
    public function oldPathTaken(string $language, string $path, ?Model $excluding): bool
    {
        $query = $this->query()
            ->where('language', $language)
            ->where('old_path', $path);

        if ($excluding instanceof Model && $excluding->exists) {
            $query->whereKeyNot($excluding->getKey());
        }

        return $query->exists();
    }

    public function countActive(): int
    {
        return $this->query()->where('active', true)->count();
    }
}
