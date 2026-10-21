<?php

/*
 * This file is part of ernestdefoe/bouncer.
 *
 * A bot guard on the forum's own server: AI crawlers turned away or let in
 * one by one, search engines verified by DNS, a proof-of-work challenge for
 * suspicious clients, rate limits, and a log for staff.
 */

use ErnestDefoe\Bouncer\Api;
use ErnestDefoe\Bouncer\BouncerServiceProvider;
use ErnestDefoe\Bouncer\Console\NetworksCommand;
use ErnestDefoe\Bouncer\Console\PruneCommand;
use ErnestDefoe\Bouncer\Http\ApiGuard;
use ErnestDefoe\Bouncer\Http\Guard;
use ErnestDefoe\Bouncer\Http\RobotsFiles;
use ErnestDefoe\Bouncer\Http\VerifyController;
use ErnestDefoe\Bouncer\Settings;
use Flarum\Extend;
use Flarum\Http\Middleware\SetLocale;
use Illuminate\Console\Scheduling\Event;

$prefix = 'ernestdefoe-bouncer.';

return [
    new Extend\Locales(__DIR__.'/resources/locale'),
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    (new Extend\ServiceProvider())->register(BouncerServiceProvider::class),

    (new Extend\View())->namespace('bouncer', __DIR__.'/resources/views'),

    (new Extend\Middleware('forum'))
        ->insertAfter(SetLocale::class, RobotsFiles::class)
        ->add(Guard::class),
    (new Extend\Middleware('api'))->add(ApiGuard::class),

    (new Extend\Routes('forum'))
        ->post('/bouncer/verify', 'ernestdefoe-bouncer.verify', VerifyController::class),

    (new Extend\Csrf())->exemptRoute('ernestdefoe-bouncer.verify'),

    (new Extend\Routes('api'))
        ->get('/bouncer/status', 'ernestdefoe-bouncer.status', Api\StatusController::class)
        ->get('/bouncer/log', 'ernestdefoe-bouncer.log', Api\ListLogController::class)
        ->delete('/bouncer/log', 'ernestdefoe-bouncer.log.clear', Api\ClearLogController::class)
        ->post('/bouncer/networks', 'ernestdefoe-bouncer.networks', Api\RefreshNetworksController::class)
        ->get('/bouncer/robots-check', 'ernestdefoe-bouncer.robots-check', Api\RobotsCheckController::class),

    (new Extend\Console())
        ->command(NetworksCommand::class)
        ->command(PruneCommand::class)
        ->schedule(NetworksCommand::class, function (Event $event): void {
            $event->weeklyOn(2, '4:17');
        })
        ->schedule(PruneCommand::class, function (Event $event): void {
            $event->dailyAt('4:47');
        }),

    (new Extend\Settings())
        ->default($prefix.'mode', 'on')
        ->default($prefix.'ip_header', 'none')
        ->default($prefix.'api', true)
        ->default($prefix.'everyone', false)
        ->default($prefix.'fake_engines', 'block')
        ->default($prefix.'tools', 'challenge')
        ->default($prefix.'headers', 'challenge')
        ->default($prefix.'datacentre', 'challenge')
        ->default($prefix.'burst_challenge', 60)
        ->default($prefix.'burst_block', 300)
        ->default($prefix.'challenge', 'pow')
        ->default($prefix.'difficulty', 16)
        ->default($prefix.'pass_days', 7)
        ->default($prefix.'log_days', 14)
        ->default($prefix.'robots', true)
        ->default($prefix.'ai_txt', false)
        ->default($prefix.'bots', '{}')
        ->default($prefix.'exempt_paths', Settings::DEFAULT_EXEMPT),
];
