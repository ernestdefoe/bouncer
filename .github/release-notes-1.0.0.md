## Bouncer 1.0.0: a bot guard on your own server

Bouncer turns away the AI crawlers you don't want and verifies real search engines by DNS. Suspicious clients get a quick proof-of-work check, bursts are rate limited, and staff get a log. It runs on the forum's own server: you don't need Cloudflare's proxy or a third-party captcha, and members get no extra JavaScript or database work.

- **AI crawlers, one by one.** Training crawlers (GPTBot, ClaudeBot, CCBot, Bytespider, Meta-ExternalAgent, Amazonbot…) are blocked by default. AI search bots, assistants fetching a page for someone, and SEO tools are allowed. Switch any of them either way.
- **robots.txt and ai.txt.** Blocked bots are listed in robots.txt, added to another extension's robots.txt if one exists. There's an optional ai.txt that opts out of training, and a button that checks your web server really hands those files to Flarum.
- **Real search engines are never challenged.** Googlebot, Bingbot, Applebot and others are verified by reverse and forward DNS, and cached. Anything only claiming to be one is turned away.
- **A challenge, not a captcha.** A small self-contained page has the browser work out a SHA-256 proof of work. That takes about a tenth of a second on a laptop and well under a second on a phone. The visitor then goes back to the page they asked for, with a signed cookie for that browser and network. Turnstile can be used instead if you already run a Turnstile extension.
- **What earns a challenge:** HTTP libraries and headless browsers, "browsers" without real browser headers, visitors from about 55 data-centre networks (refreshed weekly from RIPEstat), bursts above 60 requests a minute, or everyone, when you're under attack. A hard limit (HTTP 429) applies above 300 a minute.
- **Correct addresses behind a proxy.** You choose the header your proxy sets, and the overview shows what your own request carried. `CF-Connecting-IP` is believed only from Cloudflare's ranges, and IPv6 is counted per /64.
- **A log for staff,** one row per address and reason every ten minutes, a seven-day overview and the busiest bots. It's kept for 14 days by default.
- **Monitor only** mode, allow and block lists for addresses, user agents and paths, and extra or ignored networks.

Never touched: signed-in members, API keys and apps with a token, Flarum's internal API calls, anything but GET and HEAD, and the server's own health checks. An ordinary visitor costs one counter increment and no database queries.

Install with `composer require ernestdefoe/bouncer`, then `php flarum migrate` and `php flarum cache:clear`. Run Flarum's scheduler from cron to keep the network list fresh.

☕ If you like the things I have built, consider [buying me a coffee](https://buymeacoffee.com/ernestdefoe).
