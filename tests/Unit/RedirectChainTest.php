<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Tests\Unit;

use Asignua\FilamentRedirects\Support\RedirectChain;
use PHPUnit\Framework\TestCase;

class RedirectChainTest extends TestCase
{
    public function test_resolve_follows_the_chain_to_its_end(): void
    {
        $map = ['a' => 'b', 'b' => 'c', 'c' => 'd'];

        $this->assertSame('d', RedirectChain::resolve($map, 'b'));
        $this->assertSame('x', RedirectChain::resolve($map, 'x'));
    }

    public function test_resolve_never_follows_into_an_empty_target(): void
    {
        $map = ['a' => 'b', 'b' => ''];

        $this->assertSame('b', RedirectChain::resolve($map, 'a'));
    }

    public function test_resolve_stops_at_an_existing_cycle(): void
    {
        $map = ['a' => 'b', 'b' => 'a'];

        $this->assertSame('b', RedirectChain::resolve($map, 'a'));
    }

    public function test_resolve_respects_the_depth_limit(): void
    {
        $map = ['a' => 'b', 'b' => 'c', 'c' => 'd', 'd' => 'e'];

        $this->assertSame('c', RedirectChain::resolve($map, 'a', 2));
    }

    public function test_a_self_redirect_is_a_loop(): void
    {
        $this->assertTrue(RedirectChain::isLoop([], 'a', 'a'));
    }

    public function test_a_walk_that_comes_back_is_a_loop(): void
    {
        $this->assertTrue(RedirectChain::isLoop(['b' => 'c', 'c' => 'a'], 'a', 'b'));
    }

    public function test_a_foreign_cycle_is_not_this_redirects_loop(): void
    {
        $this->assertFalse(RedirectChain::isLoop(['b' => 'c', 'c' => 'b'], 'a', 'b'));
    }

    public function test_a_plain_chain_is_not_a_loop(): void
    {
        $this->assertFalse(RedirectChain::isLoop(['b' => 'c'], 'a', 'b'));
    }
}
