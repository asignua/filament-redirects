<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Commands;

use Asignua\FilamentRedirects\Repositories\NotFoundRepository;
use Illuminate\Console\Command;

/**
 * Prunes the 404 log BY AGE. `redirects:recheck` prunes it BY STATE.
 */
class PruneCommand extends Command
{
    protected $signature = 'redirects:prune {--days= : Delete rows not seen for this many days (default: not_found.retention_days)}';

    protected $description = 'Delete 404 log rows that have not been seen for a while';

    public function handle(NotFoundRepository $log): int
    {
        $option = $this->option('days');
        $days = max(0, is_numeric($option) ? (int) $option : (int) config('filament-redirects.not_found.retention_days', 90));

        $deleted = $log->prune($days);

        $this->info("Deleted 404 log rows: {$deleted} (not seen for {$days} days).");

        return self::SUCCESS;
    }
}
