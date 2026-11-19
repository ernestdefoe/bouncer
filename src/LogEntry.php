<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * One row of the staff log: an address (an IPv6 /64), what it sent and what
 * Bouncer did, counted. Written by {@see Log}, read through the API.
 *
 * @property int $id
 * @property string $ip
 * @property string $user_agent
 * @property string $action
 * @property string $reason
 * @property string|null $bot
 * @property string $path
 * @property int $hits
 * @property Carbon $first_seen
 * @property Carbon $last_seen
 */
class LogEntry extends AbstractModel
{
    /** Every action the log records. */
    public const ACTIONS = ['allowed', 'challenged', 'blocked', 'limited', 'solved', 'failed', 'would_challenge', 'would_block', 'would_limit'];

    protected $table = Log::TABLE;

    public $timestamps = false;

    protected $casts = [
        'hits' => 'integer',
        'first_seen' => 'datetime',
        'last_seen' => 'datetime',
    ];
}
