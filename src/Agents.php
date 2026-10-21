<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer;

/**
 * What a user agent says it is. Matching is lowercase substring search over
 * short lists, a few microseconds per request.
 *
 * The user agent is only a claim: search engines are verified by DNS before
 * they're trusted ({@see Crawler}), and everything let through by name still
 * counts towards the rate limit.
 */
class Agents
{
    /** Search engines: user-agent token => hostname suffixes their addresses reverse-resolve to. */
    public const ENGINES = [
        'googlebot' => ['Googlebot', ['.googlebot.com', '.google.com']],
        'google-inspectiontool' => ['Googlebot', ['.googlebot.com', '.google.com']],
        'googleother' => ['Googlebot', ['.googlebot.com', '.google.com']],
        'adsbot-google' => ['Googlebot', ['.googlebot.com', '.google.com']],
        'mediapartners-google' => ['Googlebot', ['.googlebot.com', '.google.com']],
        'storebot-google' => ['Googlebot', ['.googlebot.com', '.google.com']],
        'bingbot' => ['Bingbot', ['.search.msn.com']],
        'adidxbot' => ['Bingbot', ['.search.msn.com']],
        'bingpreview' => ['Bingbot', ['.search.msn.com']],
        'msnbot' => ['Bingbot', ['.search.msn.com']],
        'applebot' => ['Applebot', ['.applebot.apple.com']],
        'yandex' => ['Yandex', ['.yandex.ru', '.yandex.net', '.yandex.com']],
        'baiduspider' => ['Baidu', ['.baidu.com', '.baidu.jp']],
        'slurp' => ['Yahoo', ['.crawl.yahoo.net']],
        'seznambot' => ['Seznam', ['.seznam.cz']],
        'petalbot' => ['Petal', ['.petalsearch.com']],
    ];

    /**
     * Bots the admin decides on, one by one.
     * key => [name, operator, kind, blocked by default, user-agent tokens, robots.txt tokens].
     * A bot with no user-agent tokens is a robots.txt-only opt-out.
     */
    public const BOTS = [
        // Training crawlers: collect pages to train models.
        'gptbot' => ['GPTBot', 'OpenAI', 'training', true, ['gptbot'], ['GPTBot']],
        'claudebot' => ['ClaudeBot', 'Anthropic', 'training', true, ['claudebot', 'anthropic-ai', 'claude-web'], ['ClaudeBot', 'anthropic-ai', 'Claude-Web']],
        'ccbot' => ['CCBot', 'Common Crawl', 'training', true, ['ccbot'], ['CCBot']],
        'bytespider' => ['Bytespider', 'ByteDance', 'training', true, ['bytespider'], ['Bytespider']],
        'google-extended' => ['Google-Extended', 'Google (Gemini training, robots.txt only)', 'training', true, [], ['Google-Extended']],
        'applebot-extended' => ['Applebot-Extended', 'Apple (AI training, robots.txt only)', 'training', true, [], ['Applebot-Extended']],
        'meta-externalagent' => ['Meta-ExternalAgent', 'Meta', 'training', true, ['meta-externalagent', 'facebookbot'], ['meta-externalagent', 'FacebookBot']],
        'amazonbot' => ['Amazonbot', 'Amazon', 'training', true, ['amazonbot'], ['Amazonbot']],
        'cohere' => ['cohere-ai', 'Cohere', 'training', true, ['cohere-ai', 'cohere-training-data-crawler'], ['cohere-ai', 'cohere-training-data-crawler']],
        'diffbot' => ['Diffbot', 'Diffbot', 'training', true, ['diffbot'], ['Diffbot']],
        'omgili' => ['Omgilibot', 'Webz.io', 'training', true, ['omgili'], ['omgili', 'omgilibot', 'Webzio-Extended']],
        'ai2bot' => ['AI2Bot', 'Allen Institute for AI', 'training', true, ['ai2bot'], ['AI2Bot']],
        'imagesiftbot' => ['ImagesiftBot', 'Hive', 'training', true, ['imagesiftbot'], ['ImagesiftBot']],
        'timpibot' => ['Timpibot', 'Timpi', 'training', true, ['timpibot'], ['Timpibot']],
        'pangubot' => ['PanguBot', 'Huawei', 'training', true, ['pangubot'], ['PanguBot']],
        'img2dataset' => ['img2dataset', 'open-source dataset tool', 'training', true, ['img2dataset'], ['img2dataset']],
        // AI search: index pages to cite them in answers, with a link back.
        'oai-searchbot' => ['OAI-SearchBot', 'OpenAI (ChatGPT search)', 'search', false, ['oai-searchbot'], ['OAI-SearchBot']],
        'claude-searchbot' => ['Claude-SearchBot', 'Anthropic (Claude search)', 'search', false, ['claude-searchbot'], ['Claude-SearchBot']],
        'perplexitybot' => ['PerplexityBot', 'Perplexity', 'search', false, ['perplexitybot'], ['PerplexityBot']],
        'youbot' => ['YouBot', 'You.com', 'search', false, ['youbot'], ['YouBot']],
        'duckassistbot' => ['DuckAssistBot', 'DuckDuckGo', 'search', false, ['duckassistbot'], ['DuckAssistBot']],
        // Assistants fetching a page because a person asked them to.
        'chatgpt-user' => ['ChatGPT-User', 'OpenAI', 'assistant', false, ['chatgpt-user'], ['ChatGPT-User']],
        'claude-user' => ['Claude-User', 'Anthropic', 'assistant', false, ['claude-user'], ['Claude-User']],
        'perplexity-user' => ['Perplexity-User', 'Perplexity', 'assistant', false, ['perplexity-user'], ['Perplexity-User']],
        'mistralai-user' => ['MistralAI-User', 'Mistral', 'assistant', false, ['mistralai-user'], ['MistralAI-User']],
        'meta-externalfetcher' => ['Meta-ExternalFetcher', 'Meta', 'assistant', false, ['meta-externalfetcher'], ['Meta-ExternalFetcher']],
        // SEO tools.
        'ahrefsbot' => ['AhrefsBot', 'Ahrefs', 'seo', false, ['ahrefsbot', 'ahrefssiteaudit'], ['AhrefsBot']],
        'semrushbot' => ['SemrushBot', 'Semrush', 'seo', false, ['semrushbot', 'siteauditbot'], ['SemrushBot']],
        'mj12bot' => ['MJ12bot', 'Majestic', 'seo', false, ['mj12bot'], ['MJ12bot']],
        'dotbot' => ['DotBot', 'Moz', 'seo', false, ['dotbot', 'rogerbot'], ['DotBot', 'rogerbot']],
        'blexbot' => ['BLEXBot', 'WebMeUp', 'seo', false, ['blexbot'], ['BLEXBot']],
        'dataforseobot' => ['DataForSeoBot', 'DataForSEO', 'seo', false, ['dataforseobot'], ['DataForSeoBot']],
        'serpstatbot' => ['SerpstatBot', 'Serpstat', 'seo', false, ['serpstatbot'], ['serpstatbot']],
        'barkrowler' => ['Barkrowler', 'Babbar', 'seo', false, ['barkrowler'], ['Barkrowler']],
    ];

    /**
     * Fetchers that only ever read a page or two because someone shared a
     * link, monitors and feed readers: let through by name (still rate
     * limited), since they can't solve a challenge and are expected to work.
     */
    public const FRIENDLY = [
        'facebookexternalhit', 'facebookcatalog', 'twitterbot', 'slackbot', 'slack-imgproxy',
        'discordbot', 'telegrambot', 'whatsapp', 'linkedinbot', 'pinterest', 'redditbot',
        'embedly', 'iframely', 'skypeuripreview', 'microsoftpreview', 'mastodon', 'bluesky',
        'cardyb', 'vkshare', 'snapchat', 'tumblr', 'applenewsbot', 'kakaotalk-scrap', 'viber',
        'duckduckbot', 'duckduckgo-favicons-bot', 'google-read-aloud', 'feedfetcher-google',
        'uptimerobot', 'pingdom', 'statuscake', 'betteruptime', 'better stack', 'site24x7',
        'freshping', 'hetrixtools', 'updown.io', 'uptime-kuma', 'nodeping', 'checkly', 'gatus',
        'feedly', 'inoreader', 'newsblur', 'feedbin', 'tiny tiny rss', 'freshrss',
        'theoldreader', 'miniflux', 'netnewswire', 'feedburner',
    ];

    /** HTTP libraries, scanners and headless browsers: nobody reads a forum with these. */
    public const TOOLS = [
        'curl/', 'libcurl', 'wget/', 'python-requests', 'python-urllib', 'python-httpx', 'python/',
        'aiohttp', 'httpx', 'go-http-client', 'java/', 'okhttp', 'libwww-perl', 'lwp::', 'scrapy',
        'node-fetch', 'axios/', 'undici', 'guzzlehttp', 'ruby', 'apache-httpclient', 'colly',
        'mechanize', 'httrack', 'zgrab', 'masscan', 'nikto', 'sqlmap', 'nmap', 'wpscan', 'nuclei',
        'postmanruntime', 'winhttp', 'powershell', 'dart:io', 'reqwest', 'hackney', 'faraday',
        'typhoeus', 'restsharp', 'cpp-httplib', 'headlesschrome', 'phantomjs', 'slimerjs',
        'puppeteer', 'playwright', 'selenium', 'webdriver', 'htmlunit', 'electron/',
    ];

    /** Clients a link preview, monitor or feed reader may say it's built on. */
    public const BUILT_ON = [
        'apache-httpclient', 'java/', 'okhttp', 'ruby', 'guzzlehttp', 'faraday', 'typhoeus',
        'libwww-perl', 'lwp::', 'dart:io', 'reqwest', 'hackney', 'restsharp', 'winhttp', 'libcurl',
    ];

    /**
     * @return array{type: string, key: string, name: string, suffixes?: list<string>}
     *     type: engine | bot | friendly | tool | empty | browser
     */
    public static function identify(string $userAgent): array
    {
        $ua = strtolower(trim($userAgent));

        if ($ua === '') {
            return ['type' => 'empty', 'key' => '', 'name' => ''];
        }

        foreach (self::ENGINES as $token => [$name, $suffixes]) {
            if (str_contains($ua, $token)) {
                return ['type' => 'engine', 'key' => $token, 'name' => $name, 'suffixes' => $suffixes];
            }
        }

        foreach (self::BOTS as $key => $bot) {
            foreach ($bot[4] as $token) {
                if (str_contains($ua, $token)) {
                    return ['type' => 'bot', 'key' => $key, 'name' => $bot[0]];
                }
            }
        }

        $tool = null;

        foreach (self::TOOLS as $token) {
            if (str_contains($ua, $token)) {
                $tool = ['type' => 'tool', 'key' => $token, 'name' => rtrim($token, '/')];

                break;
            }
        }

        foreach (self::FRIENDLY as $token) {
            if (str_contains($ua, $token)) {
                // Real fetchers name the client they're built on (LinkedIn's
                // says Apache-HttpClient), but none is a scripting library or
                // a headless browser: "facebookexternalhit python-requests"
                // is a script borrowing a name.
                return $tool !== null && ! in_array($tool['key'], self::BUILT_ON, true)
                    ? $tool
                    : ['type' => 'friendly', 'key' => $token, 'name' => $token];
            }
        }

        if ($tool !== null) {
            return $tool;
        }

        return ['type' => 'browser', 'key' => '', 'name' => ''];
    }

    public static function botBlockedByDefault(string $key): bool
    {
        return (bool) (self::BOTS[$key][3] ?? false);
    }

    /**
     * A browser that says it is a recent Chrome, Edge or Firefox always sends
     * Sec-Fetch-* headers over HTTPS; a script borrowing its user agent
     * usually doesn't.
     */
    public static function claimsFetchMetadata(string $userAgent): bool
    {
        if (preg_match('~(?:Chrome|Chromium)/(\d+)~', $userAgent, $m) && (int) $m[1] >= 80) {
            return true;
        }

        return preg_match('~Firefox/(\d+)~', $userAgent, $m) === 1 && (int) $m[1] >= 90;
    }
}
