<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects;

use Asignua\FilamentRedirects\Resources\NotFound\NotFoundResource;
use Asignua\FilamentRedirects\Resources\Redirects\RedirectResource;
use BackedEnum;
use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * The panel side of the package: the "Redirects" and "404 log" resources.
 *
 *     ->plugin(RedirectsPlugin::make()
 *         ->authorize(fn (): bool => auth()->user()?->isAdmin())
 *         ->navigationGroup('SEO'))
 *
 * The middleware, the commands and the schedule do NOT depend on it: they live in the
 * {@see Redirects} registry and work without any panel.
 */
class RedirectsPlugin implements Plugin
{
    public const string ID = 'filament-redirects';

    /** The gate that is consulted when no `authorize()` closure was given and the gate exists. */
    public const string GATE = 'redirects.manage';

    protected bool|Closure|null $authorize = null;

    protected bool|Closure $redirects = true;

    protected bool|Closure $notFoundLog = true;

    protected string|UnitEnum|Closure|null $navigationGroup = null;

    protected ?int $navigationSort = null;

    protected string|BackedEnum|Closure|null $navigationIcon = null;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static */
        return filament(static::ID);
    }

    public function getId(): string
    {
        return static::ID;
    }

    /**
     * Who may open the resources. Default: the `redirects.manage` gate when it is defined,
     * otherwise everyone who can enter the panel.
     */
    public function authorize(bool|Closure $callback): static
    {
        $this->authorize = $callback;

        return $this;
    }

    /**
     * Show the "Redirects" resource.
     */
    public function redirects(bool|Closure $condition = true): static
    {
        $this->redirects = $condition;

        return $this;
    }

    /**
     * Show the "404 log" resource.
     */
    public function notFoundLog(bool|Closure $condition = true): static
    {
        $this->notFoundLog = $condition;

        return $this;
    }

    public function navigationGroup(string|UnitEnum|Closure|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    /**
     * The sort of the "Redirects" item; the "404 log" follows it directly.
     */
    public function navigationSort(?int $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    /**
     * The icon of the "Redirects" item (the 404 log keeps its own).
     */
    public function navigationIcon(string|BackedEnum|Closure|null $icon): static
    {
        $this->navigationIcon = $icon;

        return $this;
    }

    public function getNavigationGroup(): string|UnitEnum|null
    {
        return value($this->navigationGroup);
    }

    public function getNavigationSort(): ?int
    {
        return $this->navigationSort;
    }

    public function getNavigationIcon(): string|BackedEnum|null
    {
        return value($this->navigationIcon);
    }

    /**
     * May the current user manage redirects? Works whether or not the plugin is registered in
     * the current panel.
     */
    public static function allows(): bool
    {
        $plugin = filament()->hasPlugin(static::ID) ? static::get() : null;

        return ($plugin ?? static::make())->isAllowed();
    }

    public function isAllowed(): bool
    {
        if ($this->authorize !== null) {
            return (bool) value($this->authorize);
        }

        return !Gate::has(self::GATE) || Gate::allows(self::GATE);
    }

    public function register(Panel $panel): void
    {
        $resources = [];

        if ((bool) value($this->redirects)) {
            $resources[] = RedirectResource::class;
        }

        if ((bool) value($this->notFoundLog)) {
            $resources[] = NotFoundResource::class;
        }

        $panel->resources($resources);
    }

    public function boot(Panel $panel): void {}
}
