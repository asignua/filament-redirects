<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Repositories;

use Asignua\FilamentRedirects\Enums\RedirectCode;
use Asignua\FilamentRedirects\Models\NotFoundEntry;
use Asignua\FilamentRedirects\Models\Redirect;
use Asignua\FilamentRedirects\Redirects;
use Asignua\FilamentRedirects\Support\RedirectCache;
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
    /** The length of the `old_path` column. */
    public const int OLD_PATH_MAX = 191;

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

        return $this->fill($redirect, $data, explicitEntity: $entity !== null);
    }

    /**
     * Creates a redirect from a row of the 404 log. The log stores the path the way the middleware
     * saw it - already in the canon - so it is assigned VERBATIM: normalising it a second time
     * would strip a host-looking `https:/site.test/x` (the nginx-collapsed form of a scanned URL)
     * or a language-looking `en/foo` (the request `/en/en/foo`), and the redirect would then guard
     * an address other than the one that 404s.
     *
     * @param array<string, mixed> $data `to_path`, `code`, `active` (`old_path` and `language` come from the row)
     */
    public function createFromLog(NotFoundEntry $entry, array $data): Redirect
    {
        $class = $this->modelClass();

        return $this->fill(new $class, [...$data, 'old_path' => $entry->path, 'language' => $entry->language], oldPathCanonical: true);
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
    private function fill(Redirect $redirect, array $data, bool $explicitEntity = false, bool $oldPathCanonical = false): Redirect
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
            $rawOldPath = (string) $data['old_path'];

            // The query string is never matched (the middleware looks at the path only), so a
            // source with `?` or `#` would be saved and stay dead forever. Checked on the RAW
            // input: an encoded `%3F` / `%23` is a real path character (a request for
            // `/what%3F` matches `what?`) and must stay allowed - see RedirectPath::escape().
            if (!$oldPathCanonical && preg_match('/[?#]/', $rawOldPath) === 1) {
                throw ValidationException::withMessages([
                    'data.old_path' => __('filament-redirects::redirects.validation.query'),
                ]);
            }

            $redirect->old_path = $oldPathCanonical ? $rawOldPath : RedirectPath::normalize($rawOldPath, $language);

            // The column is a varchar(191) of the NORMALISED path: a readable error instead of a
            // SQL "data too long" (the form checks the same on the field).
            if (mb_strlen($redirect->old_path) > self::OLD_PATH_MAX) {
                throw ValidationException::withMessages([
                    'data.old_path' => __('filament-redirects::redirects.validation.too_long', ['max' => self::OLD_PATH_MAX]),
                ]);
            }
        }

        if (array_key_exists('to_path', $data)) {
            $redirect->to_path = RedirectPath::normalize((string) ($data['to_path'] ?? ''), $language, target: true);
        }

        if (array_key_exists('code', $data)) {
            $redirect->code = RedirectCode::from((int) $data['code']);
        }

        if (array_key_exists('active', $data)) {
            $redirect->active = (bool) $data['active'];
        }

        // A new redirect without the flag is active (the column default, but the model must know
        // it too: the compaction below depends on it).
        $redirect->active = (bool) ($redirect->getAttribute('active') ?? true);

        // A new redirect without a type is permanent (the column default, but the model must
        // know it too: it is not refreshed after the insert).
        $code = $redirect->getAttribute('code') ?? RedirectCode::Permanent;
        $redirect->code = $code;

        // A Gone redirect leads nowhere: whatever target was typed is dropped, so the row,
        // the cache and the table never show a target that is not served.
        if (!$code->isRedirect()) {
            $redirect->to_path = '';
        }

        // unique(old_path, language) is in the schema: a readable error instead of SQLSTATE 23000
        // (`Redirects::create()` has no form in front of it).
        if ($this->oldPathTaken($language, (string) $redirect->old_path, $redirect)) {
            throw ValidationException::withMessages([
                'data.old_path' => __('filament-redirects::redirects.validation.taken'),
            ]);
        }

        // A target on a foreign site (or an address pasted whole) takes part in neither chains
        // nor entity binding.
        $external = RedirectPath::isAbsolute($redirect->to_path);

        // The binding follows the target: a new target (or none at all - Gone, an external URL)
        // must not keep pointing at the record of the old one. Only an entity handed in by the
        // caller of THIS call survives. Done before the compaction below copies the binding onto
        // other rows.
        if (!$explicitEntity && $redirect->entity_id !== null
            && ($external || !$code->isRedirect() || $redirect->isDirty('to_path'))) {
            $redirect->entity()->dissociate();
        }

        // Loop guard + flattening of the target: only for real, ACTIVE redirects. An inactive
        // draft `b -> c` must not re-point a live `a -> b` (the visitor would follow a rule that
        // was never turned on, and switching the draft off would not undo it); the loop and
        // resolve logic sees only active rows too, so both sides agree. Activating the draft
        // later goes through update() and compacts then.
        $compactable = $redirect->active && !$external && $code->isRedirect() && $redirect->to_path !== '';

        // Only a PERMANENT rule may rewrite other rows: a temporary 302/307 `b -> c` re-pointing
        // the existing 301 `a -> b` would turn a campaign into permanent data that removing the
        // 302 never restores. A temporary row is still loop-checked and flattened (through
        // permanent rows only - see guardAndResolve()).
        $compactExisting = $compactable && $code->isPermanent();

        // One transaction: the compaction re-points OTHER rows before this one is saved, so a
        // failed save must take it back - otherwise those rows lead to a redirect that does not
        // exist.
        $redirect->getConnection()->transaction(function () use ($redirect, $external, $compactable, $compactExisting): void {
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

            if ($compactExisting) {
                $this->compactExisting($redirect);
            }

            $redirect->save();
        });

        // The `saved` event flushed the map INSIDE the transaction; a request in between could
        // have cached the old rows again. Flush once more AFTER the commit - of the OUTER one when
        // the caller (a slug-change listener in the host's service) has its own transaction: the
        // transaction above is then only a savepoint. Outside any transaction it runs right away.
        $redirect->getConnection()->afterCommit(static function (): void {
            app(RedirectCache::class)->flush();
        });

        return $redirect;
    }

    /**
     * Would `$oldPath -> $toPath` close a loop over the active redirects of the language? The
     * same check the save runs, for a form rule that wants to show it on the field.
     */
    public function wouldLoop(string $language, string $oldPath, string $toPath, ?Model $excluding = null): bool
    {
        return RedirectChain::isLoop($this->activeMap($language, $excluding), $oldPath, $toPath);
    }

    /**
     * A manual redirect A -> B: reject a loop (through ANY active redirect - a loop over a 302
     * is still a loop) and flatten the target to the final one along the chain of active
     * PERMANENT redirects of the same language. A temporary hop stays a hop: flattening
     * through it would bake a campaign target into a permanent row.
     */
    private function guardAndResolve(Redirect $redirect): void
    {
        $map = $this->activeMap($redirect->language, $redirect);

        if (RedirectChain::isLoop($map, $redirect->old_path, $redirect->to_path)) {
            throw ValidationException::withMessages([
                'data.to_path' => __('filament-redirects::redirects.validation.loop'),
            ]);
        }

        $redirect->to_path = RedirectChain::resolve(
            $this->activeMap($redirect->language, $redirect, permanentOnly: true),
            $redirect->to_path,
        );
    }

    /**
     * Everything that led to `old_path` now leads straight to the final target.
     */
    private function compactExisting(Redirect $redirect): void
    {
        $query = $this->query()
            ->where('language', $redirect->language)
            ->where('to_path', $redirect->old_path)
            // The row the new target starts from would become a self-loop (`B -> B`).
            ->where('old_path', '!=', $redirect->to_path);

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
     * The active redirects of a language (`old_path => to_path`) without the given row. Gone
     * rows are never part of it: their empty target is "nowhere", not the home page, so a
     * chain must not be followed into it.
     *
     * @return array<string, string>
     */
    private function activeMap(string $language, ?Model $excluding, bool $permanentOnly = false): array
    {
        $codes = array_values(array_filter(
            RedirectCode::cases(),
            static fn (RedirectCode $code): bool => $permanentOnly ? $code->isPermanent() : $code->isRedirect(),
        ));

        $query = $this->query()
            ->where('language', $language)
            ->where('active', true)
            ->whereIn('code', array_map(static fn (RedirectCode $code): int => $code->value, $codes));

        if ($excluding instanceof Model && $excluding->exists) {
            $query->whereKeyNot($excluding->getKey());
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
