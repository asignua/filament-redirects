<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Tests\Unit;

use Asignua\FilamentRedirects\Support\UserAgent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UserAgentTest extends TestCase
{
    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function agents(): array
    {
        return [
            'googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'Googlebot', true],
            'bingbot' => ['Mozilla/5.0 AppleWebKit/537.36 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', 'Bingbot', true],
            'gptbot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.1', 'OpenAI', true],
            'curl' => ['curl/8.4.0', 'curl', true],
            'a generic crawler' => ['SomeCrawler/1.0', 'Other bot', true],
            'chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', 'Chrome', false],
            'edge (also says Chrome)' => ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0', 'Edge', false],
            'firefox' => ['Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0', 'Firefox', false],
            'safari' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15', 'Safari', false],
            'unknown' => ['Totally/1.0', 'Other', false],
        ];
    }

    #[DataProvider('agents')]
    public function test_families(string $header, string $family, bool $bot): void
    {
        $this->assertSame(['family' => $family, 'bot' => $bot], UserAgent::detect($header));
    }

    public function test_no_header_means_no_family(): void
    {
        $this->assertSame(['family' => null, 'bot' => false], UserAgent::detect(null));
        $this->assertSame(['family' => null, 'bot' => false], UserAgent::detect('  '));
    }
}
