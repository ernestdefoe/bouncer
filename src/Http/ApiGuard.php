<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer\Http;

use ErnestDefoe\Bouncer\ClientIp;
use ErnestDefoe\Bouncer\Inspector;
use ErnestDefoe\Bouncer\Log;
use ErnestDefoe\Bouncer\Settings;
use Flarum\Http\CookieFactory;

/**
 * The guard on the API stack: guests' GET requests only, answered in JSON.
 * It never shows the challenge page, so it doesn't take one (or the view
 * factory a page needs).
 */
class ApiGuard extends Guard
{
    protected bool $api = true;

    public function __construct(Settings $settings, Inspector $inspector, ClientIp $clientIp, Log $log, CookieFactory $cookies)
    {
        parent::__construct($settings, $inspector, $clientIp, $log, $cookies, null);
    }
}
