<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A row of the 404 log: an unmatched path (without the language prefix) with a hit counter.
 *
 * Written by an upsert in {@see \Asignua\FilamentRedirects\Repositories\NotFoundRepository}
 * that bypasses Eloquent; the model is for reading (the panel), deleting and pruning.
 *
 * @property int         $id
 * @property string      $path
 * @property string      $language
 * @property int         $hits
 * @property string|null $referrer
 * @property string|null $user_agent
 * @property bool        $is_bot
 * @property Carbon      $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NotFoundEntry extends Model
{
    /** @var list<string> */
    protected $guarded = ['*'];

    public function getTable(): string
    {
        return (string) config('filament-redirects.tables.not_found', 'not_found_log');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'hits' => 'integer',
            'is_bot' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }
}
