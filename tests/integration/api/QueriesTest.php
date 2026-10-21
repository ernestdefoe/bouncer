<?php

namespace ErnestDefoe\Bouncer\Tests\integration\api;

use ErnestDefoe\Bouncer\Tests\integration\BouncerTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Ordinary visitors cost no database work at all.
 */
class QueriesTest extends BouncerTestCase
{
    #[Test]
    public function an_ordinary_guest_adds_no_queries()
    {
        $this->get('/');
        $db = $this->database();

        foreach (['/', '/api/discussions', '/d/1'] as $path) {
            $db->flushQueryLog();
            $db->enableQueryLog();
            $this->get($path);
            $queries = array_column($db->getQueryLog(), 'query');
            $db->disableQueryLog();

            foreach ($queries as $sql) {
                $this->assertStringNotContainsString('bouncer', $sql, $path);
                $this->assertStringNotContainsStringIgnoringCase('insert into', $sql, "$path writes nothing");
            }
        }
    }

    #[Test]
    public function a_flood_from_one_bot_writes_a_handful_of_rows()
    {
        $db = $this->database();
        $db->flushQueryLog();
        $db->enableQueryLog();

        for ($i = 0; $i < 40; $i++) {
            $this->get('/', ['User-Agent' => self::GPTBOT]);
        }

        $writes = array_filter(array_column($db->getQueryLog(), 'query'), fn ($q) => str_contains($q, 'bouncer_log'));
        $db->disableQueryLog();

        $this->assertSame(1, $db->table('bouncer_log')->count());
        $this->assertLessThanOrEqual(11, count($writes), '1 insert, then updates for the first ten hits only');
    }
}
