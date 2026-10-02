<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Resources\Concerns;

use Asignua\FilamentRedirects\RedirectsPlugin;
use UnitEnum;

/**
 * Navigation and authorization of both resources come from {@see RedirectsPlugin}.
 */
trait ReadsPluginNavigation
{
    public static function canAccess(): bool
    {
        return RedirectsPlugin::allows() && parent::canAccess();
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return self::redirectsPlugin()?->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        return (self::redirectsPlugin()?->getNavigationSort() ?? 10) + static::$sortOffset;
    }

    private static function redirectsPlugin(): ?RedirectsPlugin
    {
        return filament()->hasPlugin(RedirectsPlugin::ID) ? RedirectsPlugin::get() : null;
    }
}
