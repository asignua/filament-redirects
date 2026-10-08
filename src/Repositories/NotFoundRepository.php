<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Repositories;

use Asignua\FilamentRedirects\Models\NotFoundEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The 404 log: recording a hit, reading for the recheck, pruning.
 */
class NotFoundRepository
{
    /**
     * @return class-string<NotFoundEntry>
     */
    public function modelClass(): string
    {
        /** @var class-string<NotFoundEntry> */
        return (string) config('filament-redirects.models.not_found', NotFoundEntry::class);
    }

    /**
     * @return Builder<NotFoundEntry>
     */
    public function query(): Builder
    {
        return $this->modelClass()::query();
    }

    public function table(): string
    {
        return (new ($this->modelClass()))->getTable();
    }

    /**
     * Records a hit: bumps the counter of the (path, language) row or inserts it. Written
     * through the query builder - no Eloquent, no model events.
     *
     * A referrer or a User-Agent is only overwritten by a newer NON-empty one, so the row keeps
     * a useful sample rather than the last request that happened to send no header.
     * The race of two first hits is resolved by the unique index: the loser re-runs the update.
     */
    public function recordHit(string $path, string $language, ?string $referrer, ?string $family, bool $bot): void
    {
        $now = now();

        $extra = ['last_seen_at' => $now];

        if ($referrer !== null) {
            $extra['referrer'] = $referrer;
        }

        if ($family !== null) {
            $extra['user_agent'] = $family;
            $extra['is_bot'] = $bot;
        }

        $bump = fn (): int => DB::table($this->table())
            ->where('path', $path)
            ->where('language', $language)
            ->increment('hits', 1, $extra + ['updated_at' => $now]);

        if ($bump() > 0) {
            return;
        }

        try {
            DB::table($this->table())->insert([
                'path' => $path,
                'language' => $language,
                'hits' => 1,
                'referrer' => $referrer,
                'user_agent' => $family,
                'is_bot' => $bot,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            $bump();
        }
    }

    public function find(string $path, string $language): ?NotFoundEntry
    {
        return $this->query()->where('path', $path)->where('language', $language)->first();
    }

    /**
     * Deletes the rows not seen for more than `$days` days.
     */
    public function prune(int $days): int
    {
        return $this->query()->where('last_seen_at', '<', now()->subDays($days))->delete();
    }

    /**
     * Walks the whole log in chunks by id.
     *
     * @param callable(Collection<int, NotFoundEntry>): void $callback
     */
    public function chunkOrderedById(int $count, callable $callback): void
    {
        $this->query()->orderBy('id')->chunkById($count, $callback);
    }

    /**
     * The most recently seen rows (the ones a visitor may still hit), at most `$count`.
     *
     * @return Collection<int, NotFoundEntry>
     */
    public function latestSeen(int $count): Collection
    {
        return $this->query()->orderByDesc('last_seen_at')->orderByDesc('id')->limit($count)->get();
    }

    /**
     * @param array<int, int> $ids
     */
    public function deleteByIds(array $ids): int
    {
        return $this->query()->whereKey($ids)->delete();
    }

    public function sumHits(): int
    {
        return (int) $this->query()->sum('hits');
    }

    public function countPaths(): int
    {
        return $this->query()->count();
    }
}
