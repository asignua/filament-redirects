<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Support;

/**
 * A coarse family of a User-Agent for the 404 log: "Googlebot", "Chrome", "curl"... The full
 * string is deliberately not stored - it is high-cardinality noise and personal-ish data,
 * while the family answers the one question that matters ("who is asking for this dead URL").
 */
final class UserAgent
{
    /**
     * Ordered: the first match wins, so a specific bot goes before the browser it pretends to be.
     *
     * @var array<string, array{0: string, 1: bool}> pattern => [family, is bot]
     */
    private const array FAMILIES = [
        '/googlebot|google-inspectiontool|apis-google|adsbot-google|storebot-google|googleother/i' => ['Googlebot', true],
        '/bingbot|bingpreview|msnbot/i' => ['Bingbot', true],
        '/yandex/i' => ['YandexBot', true],
        '/baiduspider/i' => ['Baiduspider', true],
        '/duckduckbot/i' => ['DuckDuckBot', true],
        '/applebot/i' => ['Applebot', true],
        '/gptbot|chatgpt-user|oai-searchbot/i' => ['OpenAI', true],
        '/claudebot|claude-web|anthropic-ai/i' => ['Anthropic', true],
        '/perplexitybot/i' => ['PerplexityBot', true],
        '/ahrefsbot/i' => ['AhrefsBot', true],
        '/semrushbot/i' => ['SemrushBot', true],
        '/mj12bot/i' => ['MJ12bot', true],
        '/facebookexternalhit|facebot/i' => ['Facebook', true],
        '/twitterbot|slackbot|linkedinbot|telegrambot|whatsapp|discordbot/i' => ['Social preview', true],
        '/curl\//i' => ['curl', true],
        '/wget\//i' => ['Wget', true],
        '/python-requests|python-urllib|aiohttp|httpx/i' => ['Python', true],
        '/go-http-client/i' => ['Go', true],
        '/postmanruntime|insomnia/i' => ['API client', true],
        '/bot|crawl|spider|slurp|scan|monitor|checker|fetch/i' => ['Other bot', true],
        '/edg(e|a|ios)?\//i' => ['Edge', false],
        '/opr\/|opera/i' => ['Opera', false],
        '/firefox|fxios/i' => ['Firefox', false],
        '/chrome|crios|chromium/i' => ['Chrome', false],
        '/safari/i' => ['Safari', false],
    ];

    /**
     * @return array{family: string|null, bot: bool} a null family means no usable header
     */
    public static function detect(?string $userAgent): array
    {
        $userAgent = trim((string) $userAgent);

        if ($userAgent === '') {
            return ['family' => null, 'bot' => false];
        }

        foreach (self::FAMILIES as $pattern => [$family, $bot]) {
            if (preg_match($pattern, $userAgent) === 1) {
                return ['family' => $family, 'bot' => $bot];
            }
        }

        return ['family' => 'Other', 'bot' => false];
    }
}
