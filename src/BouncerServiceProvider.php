<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer;

use ErnestDefoe\Bouncer\Network\Networks;
use Flarum\Foundation\AbstractServiceProvider;

class BouncerServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        // One of each per app: the network lists load once, settings parse
        // once, and tests can replace DNS lookups.
        $this->container->singleton(Settings::class);
        $this->container->singleton(Networks::class);
        $this->container->singleton(Crawler::class);
    }
}
