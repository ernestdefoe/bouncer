<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer;

/**
 * What to do with one request, and why.
 */
final class Verdict
{
    public const PASS = 'pass';

    public const CHALLENGE = 'challenge';

    public const BLOCK = 'block';

    public const LIMIT = 'limit';

    public function __construct(
        public readonly string $action,
        public readonly string $reason,
        public readonly ?string $bot = null,
        public readonly bool $log = false,
    ) {
    }

    public static function pass(string $reason, ?string $bot = null, bool $log = false): self
    {
        return new self(self::PASS, $reason, $bot, $log);
    }

    /** The name the log gives this verdict's action. */
    public function logAction(bool $monitorOnly): string
    {
        if ($monitorOnly && $this->action !== self::PASS) {
            return 'would_'.$this->action;
        }

        return match ($this->action) {
            self::PASS => 'allowed',
            self::CHALLENGE => 'challenged',
            self::BLOCK => 'blocked',
            default => 'limited',
        };
    }
}
