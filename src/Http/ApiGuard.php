<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer\Http;

/**
 * The guard on the API stack: guests' GET requests only, answered in JSON.
 */
class ApiGuard extends Guard
{
    protected bool $api = true;
}
