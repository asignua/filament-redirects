<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Commands;

use Asignua\FilamentRedirects\Support\NotFoundRecheck;
use Illuminate\Console\Command;

/**
 * Unlike `redirects:prune` (by age) this prunes the 404 log BY STATE: every logged path is
 * checked and the ones where a visitor now gets something - a page or a redirect - are removed.
 */
class RecheckCommand extends Command
{
    protected $signature = 'redirects:recheck {--dry-run : Only count, delete nothing}';

    protected $description = 'Re-check the 404 log and remove paths that are no longer a 404';

    public function handle(NotFoundRecheck $recheck): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $recheck->run($dryRun);

        $this->info(sprintf(
            '%s: %d (page appeared: %d, redirected: %d). Rows checked: %d.',
            $dryRun ? 'Closed 404s found, nothing deleted' : 'Closed 404s removed',
            $result['deleted'],
            $result['resolved'],
            $result['redirected'],
            $result['checked'],
        ));

        return self::SUCCESS;
    }
}
