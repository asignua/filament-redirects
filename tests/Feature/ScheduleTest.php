<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Tests\Feature;

use Asignua\FilamentRedirects\Schedule;
use Asignua\FilamentRedirects\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule as LaravelSchedule;

class ScheduleTest extends TestCase
{
    public function test_nothing_is_scheduled_by_default(): void
    {
        $schedule = new LaravelSchedule;
        Schedule::register($schedule);

        $this->assertCount(0, $schedule->events());
    }

    public function test_when_enabled_both_commands_are_scheduled_at_the_configured_times(): void
    {
        config()->set('filament-redirects.schedule.enabled', true);
        config()->set('filament-redirects.schedule.times.prune', '01:00');

        $schedule = new LaravelSchedule;
        Schedule::register($schedule);

        $events = collect($schedule->events());

        $this->assertCount(2, $events);
        $this->assertStringContainsString('redirects:prune', (string) $events[0]->command);
        $this->assertSame('0 1 * * *', $events[0]->expression);
        $this->assertStringContainsString('redirects:recheck', (string) $events[1]->command);
        $this->assertSame('20 3 * * *', $events[1]->expression);
    }
}
