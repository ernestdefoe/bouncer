<?php

namespace ErnestDefoe\Bouncer\Tests\unit;

use ErnestDefoe\Bouncer\Network\RangeSet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RangeSetTest extends TestCase
{
    #[Test]
    public function ipv4_ranges_match_inside_and_not_outside()
    {
        $set = RangeSet::fromCidrs(['10.0.0.0/8', '192.0.2.7', '198.51.100.0/24']);

        $this->assertTrue($set->contains('10.0.0.0'));
        $this->assertTrue($set->contains('10.255.255.255'));
        $this->assertFalse($set->contains('11.0.0.0'));
        $this->assertFalse($set->contains('9.255.255.255'));
        $this->assertTrue($set->contains('192.0.2.7'));
        $this->assertFalse($set->contains('192.0.2.8'));
        $this->assertTrue($set->contains('198.51.100.200'));
        $this->assertFalse($set->contains('not an ip'));
        $this->assertFalse($set->contains(''));
    }

    #[Test]
    public function overlapping_and_touching_ranges_are_merged()
    {
        $set = RangeSet::fromCidrs(['10.0.0.0/24', '10.0.1.0/24', '10.0.0.128/25', '10.0.3.0/24']);

        $this->assertSame(2, $set->count());
        $this->assertTrue($set->contains('10.0.1.255'));
        $this->assertFalse($set->contains('10.0.2.0'));
        $this->assertTrue($set->contains('10.0.3.1'));
    }

    #[Test]
    public function ipv6_ranges_compare_as_strings_never_as_numbers()
    {
        // Every hex digit of these keys is a decimal digit: compared as
        // numbers (floats), they would collide.
        $set = RangeSet::fromCidrs(['2001:db8:1000::/48', '2001:db8:2000::/36']);

        $this->assertTrue($set->contains('2001:db8:1000::1'));
        $this->assertTrue($set->contains('2001:db8:1000:ffff:ffff:ffff:ffff:ffff'));
        $this->assertFalse($set->contains('2001:db8:1001::'));
        $this->assertTrue($set->contains('2001:db8:2fff::1'));
        $this->assertFalse($set->contains('2001:db8:3000::'));
        $this->assertFalse($set->contains('2001:db8::1'));
    }

    #[Test]
    public function mapped_ipv4_matches_ipv4_ranges()
    {
        $set = RangeSet::fromCidrs(['203.0.113.0/24']);

        $this->assertTrue($set->contains('::ffff:203.0.113.9'));
        $this->assertTrue(RangeSet::fromCidrs(['::ffff:203.0.113.0/120'])->contains('203.0.113.200'));
    }

    #[Test]
    public function the_whole_space_and_bad_entries()
    {
        $this->assertTrue(RangeSet::fromCidrs(['0.0.0.0/0'])->contains('255.255.255.255'));
        $this->assertTrue(RangeSet::fromCidrs(['::/0'])->contains('ffff::1'));
        $this->assertSame(0, RangeSet::fromCidrs(['10.0.0.0/33', '::/129', 'junk', '1.2.3.4/x', ''])->count());
    }

    #[Test]
    public function text_lists_allow_comments_commas_and_blank_lines()
    {
        $set = RangeSet::fromText("# office\n203.0.113.5 # me\n\n198.51.100.0/24, 2001:db8::/32\r\n");

        $this->assertSame(3, $set->count());
        $this->assertTrue($set->contains('2001:db8::abcd'));
    }

    #[Test]
    public function it_round_trips_through_an_array()
    {
        $set = RangeSet::fromCidrs(['10.0.0.0/8', '2001:db8::/32']);
        $copy = RangeSet::fromArray($set->toArray());

        $this->assertTrue($copy->contains('10.1.2.3'));
        $this->assertTrue($copy->contains('2001:db8::1'));
        $this->assertSame(0, RangeSet::fromArray(['v4' => 'junk', 'v6' => [[1], []]])->count());
    }

    #[Test]
    public function the_shipped_network_list_loads_and_matches_known_clouds()
    {
        $data = include __DIR__.'/../../resources/networks.php';
        $dc = RangeSet::fromArray($data['datacentre']);
        $cf = RangeSet::fromArray($data['cloudflare']);

        $this->assertGreaterThan(5000, $dc->count());
        $this->assertTrue($cf->contains('173.245.48.1'));
        $this->assertTrue($cf->contains('2606:4700::1'));
        $this->assertFalse($dc->contains('8.8.8.8'), 'Google Public DNS is not Google Cloud');
        $this->assertFalse($dc->contains('1.1.1.1'));
    }
}
