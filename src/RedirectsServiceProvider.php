<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects;

use Asignua\FilamentRedirects\Commands\FlushCommand;
use Asignua\FilamentRedirects\Commands\PruneCommand;
use Asignua\FilamentRedirects\Commands\RecheckCommand;
use Asignua\FilamentRedirects\Repositories\NotFoundRepository;
use Asignua\FilamentRedirects\Repositories\RedirectRepository;
use Asignua\FilamentRedirects\Support\RedirectCache;
use Illuminate\Console\Scheduling\Schedule as LaravelSchedule;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Deliberately registers NO middleware: a package cannot do it reliably from boot() (see
 * {@see Redirects::middleware()}). The host puts it on its catch-all route or appends it in
 * `bootstrap/app.php`.
 */
class RedirectsServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-redirects';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile()
            ->hasTranslations()
            ->hasMigrations(['create_redirects_table', 'create_not_found_log_table'])
            ->hasCommands([FlushCommand::class, PruneCommand::class, RecheckCommand::class]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(RedirectRepository::class);
        $this->app->singleton(NotFoundRepository::class);
        $this->app->singleton(RedirectCache::class);
    }

    public function packageBooted(): void
    {
        // The cache is invalidated on EVERY change of a redirect: a manual edit, a bulk delete
        // and `Redirects::create()` all end in a save() or a delete() of the model. Model events
        // are keyed by the concrete class, so the listener goes on the class from the config (a
        // host subclass), not on the base one.
        $model = app(RedirectRepository::class)->modelClass();

        // Flushed once the change is COMMITTED: a host often saves a redirect inside its own
        // transaction, and a flush before the commit lets a concurrent request cache the old rows
        // forever. Outside a transaction `afterCommit` runs right away.
        $flush = static function (Model $redirect): void {
            $redirect->getConnection()->afterCommit(static function (): void {
                app(RedirectCache::class)->flush();
            });
        };

        $model::saved($flush);
        $model::deleted($flush);

        $this->callAfterResolving(LaravelSchedule::class, Schedule::register(...));
    }
}
