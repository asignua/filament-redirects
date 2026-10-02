<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Tests\Feature;

use Asignua\FilamentRedirects\Models\NotFoundEntry;
use Asignua\FilamentRedirects\Models\Redirect;
use Asignua\FilamentRedirects\Repositories\NotFoundRepository;
use Asignua\FilamentRedirects\Repositories\RedirectRepository;
use Asignua\FilamentRedirects\Tests\TestCase;

class ConfigurationTest extends TestCase
{
    public function test_the_config_ships_with_the_documented_defaults(): void
    {
        $this->assertSame('redirects', config('filament-redirects.tables.redirects'));
        $this->assertSame('not_found_log', config('filament-redirects.tables.not_found'));
        $this->assertSame(Redirect::class, config('filament-redirects.models.redirect'));
        $this->assertSame(NotFoundEntry::class, config('filament-redirects.models.not_found'));
        $this->assertFalse(config('filament-redirects.schedule.enabled'));
        $this->assertFalse(config('filament-redirects.trailing_slash.canonical'));
        $this->assertSame(90, config('filament-redirects.not_found.retention_days'));
    }

    public function test_a_subclass_in_the_config_is_used_by_the_repositories_and_flushes_the_cache(): void
    {
        config()->set('filament-redirects.models.redirect', AuditedRedirect::class);
        $this->assertSame(AuditedRedirect::class, app(RedirectRepository::class)->modelClass());
        $this->assertSame(NotFoundEntry::class, app(NotFoundRepository::class)->modelClass());

        $redirect = app(RedirectRepository::class)->create(['old_path' => 'a', 'to_path' => 'b']);

        $this->assertInstanceOf(AuditedRedirect::class, $redirect);
    }
}

class AuditedRedirect extends Redirect {}
