<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Enums;

/**
 * The type of a redirect is its HTTP status. 301/302/307/308 send the visitor to the new
 * address; 404 is "Gone" (the stored value; the status served is `redirects.gone_status`) - the target is ignored (and, unlike an
 * unmatched path, is a deliberate decision, so it is not written to the 404 log).
 */
enum RedirectCode: int
{
    case Permanent = 301;
    case Temporary = 302;
    case TemporaryKeepMethod = 307;
    case PermanentKeepMethod = 308;
    case Gone = 404;

    public function label(): string
    {
        return __('filament-redirects::redirects.codes.'.$this->key());
    }

    public function color(): string
    {
        return match ($this) {
            self::Permanent, self::PermanentKeepMethod => 'success',
            self::Temporary, self::TemporaryKeepMethod => 'warning',
            self::Gone => 'danger',
        };
    }

    /**
     * 404 is not a redirect but "the page is gone for good".
     */
    public function isRedirect(): bool
    {
        return $this !== self::Gone;
    }

    /**
     * Browsers cache these without an expiry, so the response gets a bounded `max-age`.
     */
    public function isPermanent(): bool
    {
        return $this === self::Permanent || $this === self::PermanentKeepMethod;
    }

    /**
     * @return array<int, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    private function key(): string
    {
        return match ($this) {
            self::Permanent => 'permanent',
            self::Temporary => 'temporary',
            self::TemporaryKeepMethod => 'temporary_keep_method',
            self::PermanentKeepMethod => 'permanent_keep_method',
            self::Gone => 'gone',
        };
    }
}
