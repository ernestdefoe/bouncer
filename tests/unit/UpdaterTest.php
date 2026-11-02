<?php

namespace ErnestDefoe\Bouncer\Tests\unit;

use ErnestDefoe\Bouncer\Network\Updater;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UpdaterTest extends TestCase
{
    protected function updater(int $deadline): Updater
    {
        return new class($deadline) extends Updater {
            public function __construct(int $deadline)
            {
                $this->deadline = $deadline;
            }
        };
    }

    #[Test]
    public function every_source_is_fetched_within_the_deadline()
    {
        $mock = new MockHandler([new Response(200, [], 'a'), new Response(404), new Response(200, [], 'c')]);
        $out = $this->updater(120)->pool(new Client(['handler' => HandlerStack::create($mock)]), ['u1', 'u2', 'u3']);

        $this->assertSame(['u1' => 'a', 'u2' => null, 'u3' => 'c'], $out);
        $this->assertCount(0, $mock);
    }

    #[Test]
    public function no_request_starts_after_the_deadline_and_the_rest_count_as_failed()
    {
        $mock = new MockHandler([new Response(200, [], 'a'), new Response(200, [], 'b')]);
        $out = $this->updater(0)->pool(new Client(['handler' => HandlerStack::create($mock)]), ['u1', 'u2']);

        $this->assertSame(['u1' => null, 'u2' => null], $out);
        $this->assertCount(2, $mock);
    }
}
