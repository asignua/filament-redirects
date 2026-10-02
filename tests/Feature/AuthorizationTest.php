<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Tests\Feature;

use Asignua\FilamentRedirects\RedirectsPlugin;
use Asignua\FilamentRedirects\Resources\NotFound\NotFoundResource;
use Asignua\FilamentRedirects\Resources\Redirects\RedirectResource;
use Asignua\FilamentRedirects\Tests\TestCase;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;

class AuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->admin());
    }

    public function test_everyone_in_the_panel_is_allowed_by_default(): void
    {
        $this->assertTrue(RedirectsPlugin::allows());
        $this->assertTrue(RedirectResource::canAccess());
        $this->assertTrue(NotFoundResource::canAccess());
    }

    public function test_the_gate_decides_when_it_is_defined(): void
    {
        Gate::define(RedirectsPlugin::GATE, fn (): bool => false);

        $this->assertFalse(RedirectResource::canAccess());
        $this->assertFalse(NotFoundResource::canAccess());

        Gate::define(RedirectsPlugin::GATE, fn (): bool => true);

        $this->assertTrue(RedirectResource::canAccess());
    }

    public function test_an_authorize_closure_wins_over_the_gate(): void
    {
        Gate::define(RedirectsPlugin::GATE, fn (): bool => true);
        RedirectsPlugin::get()->authorize(fn (): bool => false);

        $this->assertFalse(RedirectResource::canAccess());
        $this->assertFalse(NotFoundResource::canAccess());
    }

    public function test_an_unauthorised_user_cannot_open_the_pages(): void
    {
        RedirectsPlugin::get()->authorize(fn (): bool => false);

        $this->get('/admin/redirects')->assertForbidden();
        $this->get('/admin/not-found')->assertForbidden();
    }

    public function test_an_authorised_user_can(): void
    {
        $this->get('/admin/redirects')->assertOk();
        $this->get('/admin/redirects/create')->assertOk();
        $this->get('/admin/not-found')->assertOk();
    }

    public function test_the_closure_can_look_at_the_user(): void
    {
        RedirectsPlugin::get()->authorize(fn (): bool => auth()->user()?->email === 'allowed@example.com');

        $this->assertFalse(RedirectResource::canAccess());

        $this->actingAs($this->admin()->forceFill(['email' => 'allowed@example.com']));

        $this->assertTrue(RedirectResource::canAccess());
    }

    public function test_either_resource_can_be_left_out_of_a_panel(): void
    {
        $noLog = Panel::make()->id('a')->plugin(RedirectsPlugin::make()->notFoundLog(false));
        $noRedirects = Panel::make()->id('b')->plugin(RedirectsPlugin::make()->redirects(false));

        $this->assertContains(RedirectResource::class, $noLog->getResources());
        $this->assertNotContains(NotFoundResource::class, $noLog->getResources());
        $this->assertNotContains(RedirectResource::class, $noRedirects->getResources());
        $this->assertContains(NotFoundResource::class, $noRedirects->getResources());

        $full = Filament::getPanel('admin');
        $this->assertContains(RedirectResource::class, $full->getResources());
        $this->assertContains(NotFoundResource::class, $full->getResources());
    }

    public function test_navigation_comes_from_the_plugin(): void
    {
        RedirectsPlugin::get()->navigationGroup('SEO')->navigationSort(20)->navigationIcon('heroicon-o-link');

        $this->assertSame('SEO', RedirectResource::getNavigationGroup());
        $this->assertSame('SEO', NotFoundResource::getNavigationGroup());
        $this->assertSame(20, RedirectResource::getNavigationSort());
        $this->assertSame(21, NotFoundResource::getNavigationSort());
        $this->assertSame('heroicon-o-link', RedirectResource::getNavigationIcon());
    }
}
