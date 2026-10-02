<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Tests;

use Asignua\FilamentRedirects\Models\NotFoundEntry;
use Asignua\FilamentRedirects\Models\Redirect;
use Asignua\FilamentRedirects\Redirects;
use Asignua\FilamentRedirects\RedirectsServiceProvider;
use Asignua\FilamentRedirects\Repositories\NotFoundRepository;
use Asignua\FilamentRedirects\Repositories\RedirectRepository;
use Asignua\FilamentRedirects\Support\RedirectCache;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;
use Workbench\App\Models\User;
use Workbench\App\Providers\AdminPanelProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /** Paths the demo "site" answers 200 for: everything else is a 404. */
    protected const array LIVE_PAGES = ['', 'live', 'en', 'en/live', 'new', 'en/new'];

    protected function setUp(): void
    {
        parent::setUp();

        Redirects::flush();
        app(RedirectCache::class)->flush();

        Filament::setCurrentPanel('admin');
    }

    protected function tearDown(): void
    {
        Redirects::flush();

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ActionsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            LivewireServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            RedirectsServiceProvider::class,
            AdminPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('app.url', 'https://site.test');
        $app['config']->set('app.locale', 'en');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('auth.providers.users.model', User::class);
    }

    /**
     * The demo site: a catch-all that answers 200 for {@see LIVE_PAGES} and 404 otherwise, with
     * the plugin's middleware on it as a host would put it. A fallback route, so that it can
     * never swallow the panel's own routes.
     */
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->fallback(function (Request $request) {
            if (in_array(trim($request->path(), '/'), self::LIVE_PAGES, true)) {
                return 'page';
            }

            abort(404);
        })->middleware(['web', ...Redirects::middleware()]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');

        foreach (['create_redirects_table', 'create_not_found_log_table'] as $stub) {
            $migration = include __DIR__.'/../database/migrations/'.$stub.'.php.stub';
            $migration->up();
        }
    }

    /**
     * Two languages: `uk` without a URL prefix, `en` with one.
     */
    protected function twoLanguages(): void
    {
        Redirects::locales(default: 'uk', all: ['uk', 'en'], unprefixed: 'uk');
    }

    protected function admin(): User
    {
        return User::factory()->create();
    }

    /**
     * A request exactly as written. `$this->get()` trims the slashes of the URI before building
     * the request, so it can never send `/old/`.
     *
     * @param array<string, string> $headers
     */
    protected function rawRequest(string $uri, string $method = 'GET', array $headers = []): TestResponse
    {
        $request = Request::create($uri, $method);

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        $kernel = $this->app->make(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return TestResponse::fromBaseResponse($response);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function redirect(string $from, string $to, array $attributes = []): Redirect
    {
        return app(RedirectRepository::class)->create($attributes + [
            'old_path' => $from,
            'to_path' => $to,
            'language' => Redirects::localeUrls()->default(),
            'code' => 301,
            'active' => true,
        ]);
    }

    protected function logEntry(string $path, string $language = 'en', int $hits = 1): NotFoundEntry
    {
        $repository = app(NotFoundRepository::class);

        for ($i = 0; $i < $hits; $i++) {
            $repository->recordHit($path, $language, null, null, false);
        }

        return $repository->find($path, $language) ?? throw new \RuntimeException('not logged');
    }
}
