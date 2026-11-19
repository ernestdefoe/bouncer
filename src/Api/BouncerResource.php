<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer\Api;

use ErnestDefoe\Bouncer\Agents;
use ErnestDefoe\Bouncer\ClientIp;
use ErnestDefoe\Bouncer\Job\RefreshNetworks;
use ErnestDefoe\Bouncer\Log;
use ErnestDefoe\Bouncer\Network\Networks;
use ErnestDefoe\Bouncer\Network\Updater;
use ErnestDefoe\Bouncer\Robots;
use ErnestDefoe\Bouncer\Settings;
use Flarum\Api\Context;
use Flarum\Api\Endpoint\Endpoint;
use Flarum\Api\Resource\AbstractResource;
use Flarum\Extension\ExtensionManager;
use Flarum\Http\UrlGenerator;
use Flarum\Queue\RoutingQueue;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\SyncQueue;
use Laminas\Diactoros\Response\JsonResponse;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * The admin page's actions under /api/bouncer, admins only. None is a record,
 * so each is a custom endpoint, as core does for its own non-CRUD actions:
 *
 *  - GET  /status       the overview: the week by action, the busiest bots,
 *                       the address Bouncer sees for the admin (a private one
 *                       means the proxy isn't passing visitors' addresses on),
 *                       the network lists' age and the robots.txt it adds to;
 *  - POST /networks     rebuild the network lists soon, answering at once;
 *  - GET  /networks     how that rebuild went;
 *  - GET  /robots-check the forum's own robots.txt and ai.txt fetched from
 *                       outside, as a crawler would (the only address ever
 *                       fetched is the forum's own configured URL).
 *
 * @extends AbstractResource<object>
 */
class BouncerResource extends AbstractResource
{
    // Built on every API request: constructors only, no queries or settings reads.
    public function __construct(
        protected Log $log,
        protected ClientIp $clientIp,
        protected Networks $networks,
        protected Robots $robots,
        protected Settings $settings,
        protected ExtensionManager $extensions,
        protected Cache $cache,
        protected Queue $queue,
        protected UrlGenerator $url,
        protected Client $http,
    ) {
    }

    public function type(): string
    {
        return 'bouncer';
    }

    /** No record ids: every path here names an action. */
    public function id(BaseContext $context): ?string
    {
        return null;
    }

    public function endpoints(): array
    {
        return [
            Endpoint::make('status')->route('GET', '/status')->admin()
                ->action(fn (Context $context) => $this->status($context)),
            Endpoint::make('refresh')->route('POST', '/networks')->admin()
                ->action(fn () => $this->refresh())
                ->response(fn (Context $context, array $state) => new JsonResponse($state, isset($state['error']) ? 429 : 202)),
            Endpoint::make('refreshState')->route('GET', '/networks')->admin()
                ->action(fn () => $this->cache->get(RefreshNetworks::STATE) ?? ['state' => 'idle']),
            Endpoint::make('robotsCheck')->route('GET', '/robots-check')->admin()
                ->action(fn () => $this->robotsCheck()),
        ];
    }

    public function fields(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function status(Context $context): array
    {
        $request = $context->request;
        $ip = $this->clientIp->resolve($request);

        return [
            'you' => [
                'ip' => $ip,
                'remote' => (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''),
                'public' => ClientIp::isPublic($ip),
                // What this request carried, so the admin can see which header their proxy sets.
                'headers' => array_filter([
                    'x-forwarded-for' => mb_substr($request->getHeaderLine('X-Forwarded-For'), 0, 200),
                    'x-real-ip' => mb_substr($request->getHeaderLine('X-Real-IP'), 0, 60),
                ]),
            ],
            'networks' => $this->networks->info() + ['configured' => Updater::asns($this->settings)],
            'defaultAsns' => Updater::DEFAULT_ASNS,
            'bots' => $this->log->topBots(),
            'catalogue' => array_map(fn (string $key, array $bot) => [
                'key' => $key,
                'name' => $bot[0],
                'operator' => $bot[1],
                'kind' => $bot[2],
                'blockedByDefault' => $bot[3],
                'robotsOnly' => $bot[4] === [],
            ], array_keys(Agents::BOTS), Agents::BOTS),
            'days' => $this->log->week(),
            'robots' => $this->robots->robotsTxt(null),
            'turnstileAvailable' => $this->extensions->isEnabled('flectar-turnstile') || $this->extensions->isEnabled('blomstra-turnstile'),
            'turnstileReady' => $this->settings->get('challenge') !== 'turnstile' || $this->settings->turnstile() !== null,
            'apcu' => function_exists('apcu_enabled') && apcu_enabled(),
        ];
    }

    /**
     * One refresh at a time. A queue worker runs it straight away. With no
     * worker (the sync queue) it would run inside this request, longer than
     * a web request may last, so the scheduler's every-minute
     * `bouncer:networks --requested` runs it instead.
     *
     * @return array<string, mixed>
     */
    protected function refresh(): array
    {
        if (! $this->cache->add(RefreshNetworks::LOCK, 1, RefreshNetworks::TTL)) {
            return ['error' => 'busy'];
        }

        $state = ['state' => 'running', 'at' => time()];
        $this->cache->put(RefreshNetworks::STATE, $state, RefreshNetworks::TTL);

        // Unwrapped as core does: Extend\Queue routing wraps the real driver.
        $driver = $this->queue instanceof RoutingQueue ? $this->queue->getDriver() : $this->queue;

        try {
            $driver instanceof SyncQueue
                ? $this->cache->put(RefreshNetworks::REQUESTED, 1, RefreshNetworks::TTL)
                : $this->queue->push(new RefreshNetworks());
        } catch (\Throwable $e) {
            // Nothing was queued: free the next try, then report as usual.
            $this->cache->forget(RefreshNetworks::LOCK);
            $this->cache->forget(RefreshNetworks::STATE);

            throw $e;
        }

        return $state;
    }

    /** @return array<string, array<string, mixed>> */
    protected function robotsCheck(): array
    {
        $base = $this->url->to('forum')->base();
        $out = [];

        foreach (['robots' => '/robots.txt', 'ai' => '/ai.txt'] as $key => $path) {
            try {
                $response = $this->http->get($base.$path, [
                    'timeout' => 8,
                    'connect_timeout' => 4,
                    'allow_redirects' => false,
                    'http_errors' => false,
                    'headers' => ['User-Agent' => 'ernestdefoe-bouncer robots check'],
                ]);
                $out[$key] = [
                    'status' => $response->getStatusCode(),
                    'bouncer' => $response->getHeaderLine('X-Bouncer') !== '',
                    'body' => substr((string) $response->getBody(), 0, 20000),
                ];
            } catch (GuzzleException $e) {
                // Shown to the admin, who asked for this check.
                $out[$key] = ['status' => 0, 'bouncer' => false, 'body' => '', 'error' => $e->getMessage()];
            }
        }

        return $out;
    }
}
