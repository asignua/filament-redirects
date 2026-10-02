<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Commands;

use Asignua\FilamentRedirects\Support\RedirectCache;
use Illuminate\Console\Command;

class FlushCommand extends Command
{
    protected $signature = 'redirects:flush';

    protected $description = 'Flush the cached map of redirects';

    public function handle(RedirectCache $cache): int
    {
        $cache->flush();
        $this->info('Redirect cache flushed.');

        return self::SUCCESS;
    }
}
