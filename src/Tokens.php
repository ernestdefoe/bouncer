<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer;

/**
 * Signed challenges and passes, keyed with the forum's own random secret.
 *
 * A challenge is bound to the visitor's address and user agent and lives ten
 * minutes. A pass (the cookie a solved challenge earns) is bound to the user
 * agent and the network (/24 or /48), so one solved challenge can't be
 * handed round a botnet; a visitor whose network changes just solves
 * another, which takes a moment and happens by itself.
 */
class Tokens
{
    public const CHALLENGE_TTL = 600;

    public function __construct(
        protected Settings $settings
    ) {
    }

    public function challenge(string $ip, string $userAgent, string $return, int $difficulty): string
    {
        $payload = implode('.', [time(), $difficulty, bin2hex(random_bytes(16)), self::b64($return)]);

        return $payload.'.'.$this->mac('c', $payload, $ip, $userAgent);
    }

    /**
     * @return array{issued: int, difficulty: int, nonce: string, return: string}|null
     */
    public function readChallenge(string $token, string $ip, string $userAgent): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 5 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1]) || ! ctype_xdigit($parts[2])) {
            return null;
        }

        $mac = array_pop($parts);
        $payload = implode('.', $parts);

        if (! hash_equals($this->mac('c', $payload, $ip, $userAgent), $mac)) {
            return null;
        }

        $issued = (int) $parts[0];

        if ($issued > time() + 5 || $issued < time() - self::CHALLENGE_TTL) {
            return null;
        }

        $return = base64_decode(strtr($parts[3], '-_', '+/'), true);

        return [
            'issued' => $issued,
            'difficulty' => (int) $parts[1],
            'nonce' => $parts[2],
            'return' => is_string($return) ? $return : '/',
        ];
    }

    public function pass(string $userAgent, string $network, int $lifetime): string
    {
        $expires = (string) (time() + $lifetime);

        return $expires.'.'.$this->mac('p', $expires, $network, $userAgent);
    }

    /**
     * The pass's signature if it's good (until it expires, plus a grace
     * period for API calls of a page left open), else null.
     */
    public function validPass(?string $cookie, string $userAgent, string $network, int $grace = 0): ?string
    {
        if ($cookie === null || ! preg_match('/^(\d{10,12})\.([0-9a-f]{32})$/', $cookie, $m)) {
            return null;
        }

        return (int) $m[1] + $grace >= time() && hash_equals($this->mac('p', $m[1], $network, $userAgent), $m[2]) ? $m[2] : null;
    }

    /** Whether sha256(challenge:solution) starts with $bits zero bits. */
    public static function solves(string $challenge, string $solution, int $bits): bool
    {
        if (! preg_match('/^\d{1,12}$/', $solution)) {
            return false;
        }

        $hash = hash('sha256', $challenge.':'.$solution, true);
        $full = intdiv($bits, 8);

        if (strncmp($hash, str_repeat("\0", $full), $full) !== 0) {
            return false;
        }

        $rest = $bits % 8;

        return $rest === 0 || (ord($hash[$full]) >> (8 - $rest)) === 0;
    }

    protected function mac(string $kind, string $payload, string $ip, string $userAgent): string
    {
        return substr(hash_hmac('sha256', $kind."\n".$payload."\n".$ip."\n".$userAgent, $this->settings->secret()), 0, 32);
    }

    protected static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
