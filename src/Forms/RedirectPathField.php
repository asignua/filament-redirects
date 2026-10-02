<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Forms;

use Asignua\FilamentRedirects\Redirects;
use Asignua\FilamentRedirects\Repositories\RedirectRepository;
use Asignua\FilamentRedirects\Support\RedirectPath;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;

/**
 * The path fields of a redirect. They show the base URL of the site plus the language segment
 * as a prefix and keep the VALUE with a leading slash, so a redirect to the home page is typed
 * as `/`. The prefix disappears as soon as an address on a FOREIGN domain is typed: such a
 * target is stored as a whole URL and leads off-site. The canon and its reasons are in
 * {@see RedirectPath}.
 *
 * The normalisation itself (stripping our own domain and language prefix) lives in
 * {@see RedirectRepository}, not here: there are several entrances to the form and exactly one
 * repository.
 */
class RedirectPathField
{
    public static function oldPath(?Closure $languageResolver = null): TextInput
    {
        $language = $languageResolver ?? self::languageFromForm();

        return self::base('old_path', $language)
            ->label(__('filament-redirects::redirects.fields.old_path'))
            ->required()
            ->rule(static function (Get $get, ?Model $record) use ($language): Closure {
                return static function (string $attribute, mixed $value, Closure $fail) use ($get, $record, $language): void {
                    $locale = (string) $language($get);
                    $raw = trim((string) $value);

                    if ($raw === '') {
                        return; // emptiness is `required`'s business
                    }

                    if (preg_match('/\s/u', $raw) === 1) {
                        $fail(__('filament-redirects::redirects.validation.spaces'));

                        return;
                    }

                    $path = RedirectPath::normalize($raw, $locale);

                    // The source is always an address of THIS site: a request for a foreign host
                    // never reaches the app, so the rule could never fire.
                    if (RedirectPath::isExternal($path)) {
                        $fail(__('filament-redirects::redirects.validation.old_external'));

                        return;
                    }

                    // The home page never 404s, so a redirect on it would be dead.
                    if ($path === Redirects::ROOT) {
                        $fail(__('filament-redirects::redirects.validation.home'));

                        return;
                    }

                    // `en/shop` on a row of the unprefixed language: an incoming `/en/shop` is
                    // parsed as ('en', 'shop'), so this row could never match. Pick that
                    // language instead of typing its prefix. (A row of a PREFIXED language has
                    // no such problem: its path is matched after the prefix is split off.)
                    $urls = Redirects::localeUrls();

                    if ($locale === $urls->unprefixed() && $urls->parse($path)[0] !== $locale) {
                        $fail(__('filament-redirects::redirects.validation.language_prefix'));

                        return;
                    }

                    // unique(old_path, language) is in the schema: without this check a duplicate
                    // surfaces as a raw SQLSTATE 23000 error.
                    if (app(RedirectRepository::class)->oldPathTaken($locale, $path, $record)) {
                        $fail(__('filament-redirects::redirects.validation.taken'));
                    }
                };
            });
    }

    public static function toPath(?Closure $languageResolver = null, ?Closure $oldPathResolver = null): TextInput
    {
        $language = $languageResolver ?? self::languageFromForm();
        $oldPath = $oldPathResolver ?? static fn (Get $get): string => (string) ($get('old_path') ?? '');

        return self::base('to_path', $language)
            ->label(__('filament-redirects::redirects.fields.new_path'))
            ->helperText(__('filament-redirects::redirects.fields.new_path_help'))
            ->rule(static function (Get $get) use ($language, $oldPath): Closure {
                return static function (string $attribute, mixed $value, Closure $fail) use ($get, $language, $oldPath): void {
                    $locale = (string) $language($get);
                    $raw = trim((string) $value);

                    if ($raw === '') {
                        return; // empty = Gone; `requiredUnless` handles the rest
                    }

                    if (preg_match('/\s/u', $raw) === 1) {
                        $fail(__('filament-redirects::redirects.validation.spaces'));

                        return;
                    }

                    // `ftp://x`, `mailto:a@b` or a bare `https://` would be glued into the path and
                    // never lead anywhere: only http(s) addresses with a host are targets.
                    // (`https:/host/x`, the form nginx leaves of a scanned URL, is accepted.)
                    if (preg_match('~^[a-z][a-z0-9+.\-]*:~i', $raw) === 1
                        && preg_match('~^https?:/{1,2}[^/?#\s]+~i', $raw) !== 1) {
                        $fail(__('filament-redirects::redirects.validation.invalid_url'));

                        return;
                    }

                    $path = RedirectPath::normalize($raw, $locale);

                    if (RedirectPath::isExternal($path)) {
                        if (filter_var($path, FILTER_VALIDATE_URL) === false) {
                            $fail(__('filament-redirects::redirects.validation.invalid_url'));
                        }

                        return; // chains and loops concern internal targets only
                    }

                    if ($path === RedirectPath::normalize((string) $oldPath($get), $locale)) {
                        $fail(__('filament-redirects::redirects.validation.loop'));
                    }
                };
            });
    }

    /**
     * What both fields have in common: the affix, the leading slash in the value, the column
     * maximum. `live(onBlur:)` is needed for the affix - once the value becomes a foreign URL
     * the prefix must go, or the field would lie about where the target is.
     */
    private static function base(string $name, Closure $language): TextInput
    {
        return TextInput::make($name)
            ->maxLength($name === 'old_path' ? 191 : 2048)
            ->live(onBlur: true)
            ->formatStateUsing(static fn (?string $state): string => RedirectPath::display($state))
            ->prefix(static fn (Get $get): ?string => RedirectPath::isExternal((string) ($get($name) ?? ''))
                ? null
                : RedirectPath::prefix((string) $language($get)));
    }

    /**
     * The language from the (live) select of the same form. Outside the redirect form - in the
     * modal of the 404 log, say - the row decides, hence the resolver parameter.
     */
    private static function languageFromForm(): Closure
    {
        return static fn (Get $get): string => (string) ($get('language') ?: Redirects::localeUrls()->default());
    }
}
