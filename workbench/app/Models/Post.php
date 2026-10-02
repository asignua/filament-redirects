<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A demo destination: redirects can be tied to the record they lead to.
 *
 * @property int    $id
 * @property string $slug
 */
class Post extends Model
{
    protected $guarded = [];
}
