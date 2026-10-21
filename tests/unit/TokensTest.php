<?php

namespace ErnestDefoe\Bouncer\Tests\unit;

use ErnestDefoe\Bouncer\Settings;
use ErnestDefoe\Bouncer\Tokens;
use Flarum\Settings\SettingsRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TokensTest extends TestCase
{
    public static function settings(array $values = []): Settings
    {
        return new Settings(new class($values) implements SettingsRepositoryInterface {
            public function __construct(public array $values)
            {
            }

            public function all(): array
            {
                return $this->values;
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }

            public function set(string $key, mixed $value): void
            {
                $this->values[$key] = $value;
            }

            public function delete(string $keyLike): void
            {
                unset($this->values[$keyLike]);
            }
        });
    }

    protected function tokens(): Tokens
    {
        return new Tokens(self::settings(['ernestdefoe-bouncer.secret' => str_repeat('ab', 32)]));
    }

    #[Test]
    public function a_challenge_reads_back_only_for_the_same_address_and_agent()
    {
        $tokens = $this->tokens();
        $token = $tokens->challenge('203.0.113.5', 'UA', '/d/1-hello?page=2', 12);

        $data = $tokens->readChallenge($token, '203.0.113.5', 'UA');
        $this->assertSame('/d/1-hello?page=2', $data['return']);
        $this->assertSame(12, $data['difficulty']);

        $this->assertNull($tokens->readChallenge($token, '203.0.113.6', 'UA'));
        $this->assertNull($tokens->readChallenge($token, '203.0.113.5', 'UA2'));
    }

    #[Test]
    public function a_tampered_challenge_is_rejected()
    {
        $tokens = $this->tokens();
        $token = $tokens->challenge('203.0.113.5', 'UA', '/', 16);

        // Lower the difficulty.
        $parts = explode('.', $token);
        $parts[1] = '1';
        $this->assertNull($tokens->readChallenge(implode('.', $parts), '203.0.113.5', 'UA'));
        $this->assertNull($tokens->readChallenge('junk', '203.0.113.5', 'UA'));
        $this->assertNull($tokens->readChallenge('', '203.0.113.5', 'UA'));
    }

    #[Test]
    public function a_challenge_from_another_forum_is_rejected()
    {
        $token = $this->tokens()->challenge('203.0.113.5', 'UA', '/', 16);
        $other = new Tokens(self::settings(['ernestdefoe-bouncer.secret' => str_repeat('cd', 32)]));

        $this->assertNull($other->readChallenge($token, '203.0.113.5', 'UA'));
    }

    #[Test]
    public function passes_expire_and_are_bound_to_the_user_agent_and_network()
    {
        $tokens = $this->tokens();
        $pass = $tokens->pass('UA', 'cb0071', 3600);

        $this->assertNotNull($tokens->validPass($pass, 'UA', 'cb0071'));
        $this->assertNull($tokens->validPass($pass, 'Other UA', 'cb0071'));
        $this->assertNull($tokens->validPass($pass, 'UA', 'c63364'), 'another network');
        $this->assertNull($tokens->validPass($pass.'0', 'UA', 'cb0071'));
        $this->assertNull($tokens->validPass(null, 'UA', 'cb0071'));

        [$expires, $mac] = explode('.', $pass);
        $this->assertNull($tokens->validPass(($expires + 86400).'.'.$mac, 'UA', 'cb0071'), 'extending the expiry breaks the signature');

        $expired = $tokens->pass('UA', 'cb0071', -10);
        $this->assertNull($tokens->validPass($expired, 'UA', 'cb0071'));
        $this->assertNotNull($tokens->validPass($expired, 'UA', 'cb0071', 86400), 'within the grace period');
    }

    #[Test]
    public function proof_of_work_checks_leading_zero_bits()
    {
        $challenge = 'abc';
        $solution = 0;

        while (! Tokens::solves($challenge, (string) $solution, 12)) {
            $solution++;
        }

        $hash = hash('sha256', "abc:$solution");
        $this->assertStringStartsWith('000', $hash);
        $this->assertFalse(Tokens::solves($challenge, '-1', 1));
        $this->assertFalse(Tokens::solves($challenge, '1e5', 1));
        $this->assertFalse(Tokens::solves($challenge, '', 0));
        $this->assertTrue(Tokens::solves($challenge, (string) $solution, 0));
    }

    #[Test]
    public function the_secret_is_made_on_first_use_and_kept()
    {
        $settings = self::settings();
        $secret = $settings->secret();

        $this->assertSame(64, strlen($secret));
        $this->assertSame($secret, $settings->secret());
    }
}
