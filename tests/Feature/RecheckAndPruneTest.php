<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Tests\Feature;

use Asignua\FilamentRedirects\Models\NotFoundEntry;
use Asignua\FilamentRedirects\Redirects;
use Asignua\FilamentRedirects\Support\NotFoundRecheck;
use Asignua\FilamentRedirects\Tests\TestCase;

class RecheckAndPruneTest extends TestCase
{
    public function test_a_path_that_now_has_a_redirect_is_closed(): void
    {
        $this->logEntry('moved');
        $this->logEntry('still-missing');
        $this->redirect('moved', 'new');

        $result = app(NotFoundRecheck::class)->run();

        $this->assertSame(['checked' => 2, 'resolved' => 0, 'redirected' => 1, 'deleted' => 1], $result);
        $this->assertSame(['still-missing'], NotFoundEntry::query()->pluck('path')->all());
    }

    public function test_a_gone_redirect_closes_the_row_too(): void
    {
        $this->logEntry('removed');
        $this->redirect('removed', '', ['code' => 404]);

        $this->assertSame(1, app(NotFoundRecheck::class)->run()['redirected']);
    }

    public function test_an_inactive_redirect_does_not_close_anything(): void
    {
        $this->logEntry('moved');
        $this->redirect('moved', 'new', ['active' => false]);

        $this->assertSame(0, app(NotFoundRecheck::class)->run()['deleted']);
    }

    public function test_a_path_that_now_resolves_is_closed_when_the_host_says_so(): void
    {
        $this->logEntry('now-a-page');

        $this->assertSame(0, app(NotFoundRecheck::class)->run()['deleted'], 'without a resolver only redirects count');

        Redirects::resolvesUsing(fn (string $language, string $path): bool => $path === 'now-a-page');

        $result = app(NotFoundRecheck::class)->run();

        $this->assertSame(1, $result['resolved']);
        $this->assertSame(0, NotFoundEntry::query()->count());
    }

    public function test_the_language_is_taken_into_account(): void
    {
        $this->twoLanguages();
        $this->logEntry('moved', 'en');
        $this->redirect('moved', 'new', ['language' => 'uk']);

        $this->assertSame(0, app(NotFoundRecheck::class)->run()['deleted']);
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $this->logEntry('moved');
        $this->redirect('moved', 'new');

        $result = app(NotFoundRecheck::class)->run(dryRun: true);

        $this->assertSame(1, $result['deleted']);
        $this->assertSame(1, NotFoundEntry::query()->count());
    }

    public function test_the_recheck_command(): void
    {
        $this->logEntry('moved');
        $this->redirect('moved', 'new');

        $this->artisan('redirects:recheck --dry-run')->expectsOutputToContain('nothing deleted')->assertSuccessful();
        $this->assertSame(1, NotFoundEntry::query()->count());

        $this->artisan('redirects:recheck')->expectsOutputToContain('removed: 1')->assertSuccessful();
        $this->assertSame(0, NotFoundEntry::query()->count());
    }

    public function test_prune_deletes_by_age(): void
    {
        $old = $this->logEntry('old');
        $this->logEntry('fresh');
        NotFoundEntry::query()->whereKey($old->id)->update(['last_seen_at' => now()->subDays(100)]);

        $this->artisan('redirects:prune')->assertSuccessful();

        $this->assertSame(['fresh'], NotFoundEntry::query()->pluck('path')->all());
    }

    public function test_prune_takes_the_horizon_from_an_option(): void
    {
        $entry = $this->logEntry('week-old');
        NotFoundEntry::query()->whereKey($entry->id)->update(['last_seen_at' => now()->subDays(8)]);

        $this->artisan('redirects:prune --days=30')->assertSuccessful();
        $this->assertSame(1, NotFoundEntry::query()->count());

        $this->artisan('redirects:prune --days=7')->assertSuccessful();
        $this->assertSame(0, NotFoundEntry::query()->count());
    }

    public function test_the_flush_command_forgets_the_map(): void
    {
        $this->redirect('old', 'new');
        $this->get('/old');
        $this->assertTrue(cache()->has('filament-redirects.map'));

        $this->artisan('redirects:flush')->assertSuccessful();

        $this->assertFalse(cache()->has('filament-redirects.map'));
    }
}
