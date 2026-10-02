<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Models;

use Asignua\FilamentRedirects\Enums\RedirectCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One redirect rule for one language. `old_path` / `to_path` are in the canon of
 * {@see \Asignua\FilamentRedirects\Support\RedirectPath}.
 *
 * No mass assignment: all writes go through
 * {@see \Asignua\FilamentRedirects\Repositories\RedirectRepository}, which normalises the
 * paths and guards loops. A field that is not assigned there is silently not saved.
 *
 * @property int          $id
 * @property string       $old_path
 * @property string       $to_path
 * @property string       $language
 * @property RedirectCode $code
 * @property int          $hits
 * @property Carbon|null  $last_hit_at
 * @property bool         $active
 * @property string|null  $entity_type
 * @property int|null     $entity_id
 * @property Carbon|null  $created_at
 * @property Carbon|null  $updated_at
 * @property Model|null   $entity
 */
class Redirect extends Model
{
    /** @var list<string> */
    protected $guarded = ['*'];

    public function getTable(): string
    {
        return (string) config('filament-redirects.tables.redirects', 'redirects');
    }

    /**
     * The record the redirect leads to (optional, set by `Redirects::entityUsing()`).
     *
     * @return MorphTo<Model, $this>
     */
    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'code' => RedirectCode::class,
            'hits' => 'integer',
            'last_hit_at' => 'datetime',
            'active' => 'boolean',
        ];
    }
}
