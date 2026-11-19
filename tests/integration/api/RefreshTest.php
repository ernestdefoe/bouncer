<?php

namespace ErnestDefoe\Bouncer\Tests\integration\api;

use ErnestDefoe\Bouncer\Console\NetworksCommand;
use ErnestDefoe\Bouncer\Job\RefreshNetworks;
use ErnestDefoe\Bouncer\Network\Networks;
use ErnestDefoe\Bouncer\Network\RangeSet;
use ErnestDefoe\Bouncer\Network\Updater;
use ErnestDefoe\Bouncer\Tests\integration\BouncerTestCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Queue\NullQueue;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * "Refresh now" never runs in the request: a queue worker gets the job, or,
 * with no worker (the sync queue, as in these tests), the scheduler's
 * every-minute `bouncer:networks --requested` runs it. The admin page polls
 * for the outcome.
 */
class RefreshTest extends BouncerTestCase
{
    protected function tearDown(): void
    {
        @unlink($this->app()->getContainer()->make(Networks::class)->file());

        parent::tearDown();
    }

    /** Every source answers (or, with $fail, RIPEstat fails), without the network. */
    protected function sources(bool $fail = false): void
    {
        $this->app()->getContainer()->resolving(Updater::class, function (Updater $updater) use ($fail) {
            $updater->fetchWith(fn (array $urls) => array_combine($urls, array_map(fn (string $url) => match (true) {
                str_contains($url, 'ripe.net') => $fail ? null : json_encode(['status' => 'ok', 'data' => ['prefixes' => [['prefix' => '192.0.2.0/24']]]]),
                str_ends_with($url, 'v4') => "173.245.48.0/20\n",
                default => "2400:cb00::/32\n",
            }, $urls)));
        });
    }

    protected function call(string $method): array
    {
        $response = $this->send($this->request($method, '/api/bouncer/networks', ['authenticatedAs' => 1]));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    /** What the scheduler runs every minute; returns its exit code. */
    protected function scheduler(): int
    {
        return $this->app()->getContainer()->make(NetworksCommand::class)->run(new ArrayInput(['--requested' => true]), new BufferedOutput());
    }

    #[Test]
    public function without_a_worker_the_scheduler_runs_it_through_the_disk_and_reports_done()
    {
        $this->sources();

        $this->assertSame('idle', $this->call('GET')[1]['state']);
        $this->assertSame(0, $this->scheduler(), 'nothing waiting: nothing done');
        $this->assertFileDoesNotExist($this->app()->getContainer()->make(Networks::class)->file());

        [$status, $body] = $this->call('POST');
        $this->assertSame(202, $status);
        $this->assertSame('running', $body['state']);
        $this->assertFileDoesNotExist($this->app()->getContainer()->make(Networks::class)->file(), 'not in the request');

        $this->assertSame(0, $this->scheduler());

        $state = $this->call('GET')[1];
        $this->assertSame('done', $state['state'], 'the admin page polls this');
        $this->assertSame(1, $state['result']['ranges']);
        $this->assertSame('storage', $state['networks']['source']);

        $file = $this->app()->getContainer()->make(Networks::class)->file();
        $written = RangeSet::fromArray((include $file)['datacentre']);
        $this->assertTrue($written->contains('192.0.2.9'));
        $this->assertFalse($written->contains('198.51.100.9'));
        $this->assertSame([], glob(dirname($file).'/*.tmp'), 'no temporary file is left behind');

        $this->assertNull($this->app()->getContainer()->make(Cache::class)->get(RefreshNetworks::LOCK), 'the lock is released');
        $this->assertSame(202, $this->call('POST')[0], 'and the next refresh may start');
    }

    #[Test]
    public function with_a_worker_the_job_is_queued_and_the_request_returns_at_once()
    {
        $this->sources();
        $queue = new class extends NullQueue {
            public array $jobs = [];

            public function push($job, $data = '', $queue = null)
            {
                $this->jobs[] = $job;
            }
        };
        $this->app()->getContainer()->instance('flarum.queue.connection', $queue);

        [$status, $body] = $this->call('POST');
        $this->assertSame(202, $status);
        $this->assertSame('running', $body['state']);
        $this->assertCount(1, $queue->jobs);
        $this->assertInstanceOf(RefreshNetworks::class, $queue->jobs[0]);
        $this->assertSame(0, $this->scheduler(), 'the scheduler leaves it to the worker');
        $this->assertFileDoesNotExist($this->app()->getContainer()->make(Networks::class)->file());

        $this->app()->getContainer()->call([$queue->jobs[0], 'handle']);
        $this->assertSame('done', $this->call('GET')[1]['state']);
    }

    #[Test]
    public function a_failed_source_writes_nothing_and_reports_why()
    {
        $this->sources(fail: true);

        $this->call('POST');
        $this->assertSame(1, $this->scheduler());

        $state = $this->call('GET')[1];
        $this->assertSame('failed', $state['state']);
        $this->assertStringContainsString('RIPEstat', $state['error']);
        $this->assertFileDoesNotExist($this->app()->getContainer()->make(Networks::class)->file());
        $this->assertNull($this->app()->getContainer()->make(Cache::class)->get(RefreshNetworks::LOCK));
    }

    #[Test]
    public function only_one_refresh_runs_at_a_time()
    {
        $this->sources();
        $this->app()->getContainer()->make(Cache::class)->put(RefreshNetworks::LOCK, 1, 60);

        $this->assertSame(429, $this->call('POST')[0]);
        $this->assertFileDoesNotExist($this->app()->getContainer()->make(Networks::class)->file());
    }

    #[Test]
    public function the_commands_are_scheduled()
    {
        $events = $this->app()->getContainer()->make(Schedule::class)->events();
        $find = fn (string $name, string $cron) => array_filter($events, fn ($e) => str_contains((string) $e->command, $name) && $e->expression === $cron);

        $this->assertNotEmpty($find('bouncer:networks', '17 4 * * 2'), 'weekly refresh');
        $this->assertNotEmpty($find('bouncer:networks --requested', '* * * * *'), "every minute, for an admin's refresh");
        $this->assertNotEmpty($find('bouncer:prune', '47 4 * * *'), 'daily prune');

        // The every-minute run starts no process unless a refresh is waiting.
        $container = $this->app()->getContainer();
        $requested = array_values($find('bouncer:networks --requested', '* * * * *'))[0];
        $this->assertFalse($requested->filtersPass($container));
        $container->make(Cache::class)->put(RefreshNetworks::REQUESTED, 1, 60);
        $this->assertTrue($requested->filtersPass($container));
    }
}
