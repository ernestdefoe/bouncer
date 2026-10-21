<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer\Api;

use Carbon\Carbon;
use ErnestDefoe\Bouncer\Agents;
use ErnestDefoe\Bouncer\ClientIp;
use ErnestDefoe\Bouncer\Log;
use ErnestDefoe\Bouncer\Network\Networks;
use ErnestDefoe\Bouncer\Network\Updater;
use ErnestDefoe\Bouncer\Robots;
use ErnestDefoe\Bouncer\Settings;
use Flarum\Extension\ExtensionManager;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * What the admin page's overview shows: the last seven days by action, the
 * busiest bots, the address Bouncer sees for the admin (a private one means
 * the reverse proxy isn't passing visitors' addresses on), the network
 * lists' age and the robots.txt Bouncer adds to. Admins only.
 */
class StatusController implements RequestHandlerInterface
{
    public function __construct(
        protected ConnectionInterface $db,
        protected ClientIp $clientIp,
        protected Networks $networks,
        protected Updater $updater,
        protected Robots $robots,
        protected Settings $settings,
        protected ExtensionManager $extensions,
    ) {
    }

    public function handle(Request $request): Response
    {
        RequestUtil::getActor($request)->assertAdmin();

        $ip = $this->clientIp->resolve($request);

        // One grouped query: a sum per day, each day a CASE column.
        $starts = [];
        $columns = [];
        $bindings = [];

        for ($i = 6; $i >= 0; $i--) {
            $start = Carbon::now('UTC')->startOfDay()->subDays($i);
            $starts[] = $start;
            $columns[] = 'sum(case when last_seen >= ? and last_seen < ? then hits else 0 end) as d'.(6 - $i);
            array_push($bindings, $start, $start->copy()->addDay());
        }

        $rows = $this->db->table(Log::TABLE)
            ->where('last_seen', '>=', $starts[0])
            ->groupBy('action')
            ->selectRaw('action, '.implode(', ', $columns), $bindings)
            ->get();

        $days = [];

        foreach ($starts as $i => $start) {
            $counts = [];

            foreach ($rows as $row) {
                if ((int) $row->{'d'.$i} > 0) {
                    $counts[$row->action] = (int) $row->{'d'.$i};
                }
            }

            $days[] = ['date' => $start->toDateString(), 'counts' => $counts];
        }

        $since = Carbon::now('UTC')->subDays(7);
        $bots = $this->db->table(Log::TABLE)
            ->whereNotNull('bot')
            ->where('last_seen', '>=', $since)
            ->groupBy('bot', 'action', 'reason')
            ->selectRaw('bot, action, reason, sum(hits) as hits')
            ->orderByDesc('hits')
            ->limit(15)
            ->get()
            ->map(fn ($row) => ['bot' => $row->bot, 'action' => $row->action, 'reason' => $row->reason, 'hits' => (int) $row->hits])
            ->all();

        return new JsonResponse([
            'you' => [
                'ip' => $ip,
                'remote' => (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''),
                'public' => ClientIp::isPublic($ip),
            ],
            'networks' => $this->networks->info() + ['configured' => $this->updater->asns()],
            'defaultAsns' => Updater::DEFAULT_ASNS,
            'bots' => $bots,
            'catalogue' => array_map(fn (string $key, array $bot) => [
                'key' => $key,
                'name' => $bot[0],
                'operator' => $bot[1],
                'kind' => $bot[2],
                'blockedByDefault' => $bot[3],
                'robotsOnly' => $bot[4] === [],
            ], array_keys(Agents::BOTS), Agents::BOTS),
            'days' => $days,
            'robots' => $this->robots->robotsTxt(null),
            'turnstileAvailable' => $this->extensions->isEnabled('flectar-turnstile') || $this->extensions->isEnabled('blomstra-turnstile'),
            'turnstileReady' => $this->settings->get('challenge') !== 'turnstile' || $this->settings->turnstile() !== null,
            'apcu' => function_exists('apcu_enabled') && apcu_enabled(),
        ]);
    }
}
