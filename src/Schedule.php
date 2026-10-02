<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects;

use Illuminate\Console\Scheduling\Schedule as LaravelSchedule;

/**
 * Registers the housekeeping of the 404 log. Off by default (`filament-redirects.schedule.enabled`).
 *
 * A separate class, not the body of a provider callback, so that a test can pass a fresh
 * `Schedule` with the config it needs.
 */
final class Schedule
{
    public static function register(LaravelSchedule $schedule): void
    {
        if (!(bool) config('filament-redirects.schedule.enabled', false)) {
            return;
        }

        $schedule->command('redirects:prune')
            ->dailyAt((string) config('filament-redirects.schedule.times.prune', '03:10'))
            ->withoutOverlapping();

        $schedule->command('redirects:recheck')
            ->dailyAt((string) config('filament-redirects.schedule.times.recheck', '03:20'))
            ->withoutOverlapping();
    }
}
