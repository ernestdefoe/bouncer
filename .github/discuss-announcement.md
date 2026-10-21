Look at almost any forum's server logs and much of the traffic isn't people. AI training crawlers read every page, scrapers run from cloud servers, and scripts call themselves Googlebot. The usual answer is to put the forum behind Cloudflare's proxy, which brings upload limits and, sooner or later, a bill.

**Bouncer** does the job on your own server. It turns away the crawlers you don't want, lets real search engines straight in, and gives anything suspicious a quick check that real browsers pass by themselves. Members never notice it: no JavaScript is added to the forum and no database queries are added for visitors.

![The challenge a suspicious client sees](https://raw.githubusercontent.com/ernestdefoe/bouncer/main/screenshots/challenge.png)

## You choose which AI crawlers read your forum

Training crawlers (GPTBot, ClaudeBot, CCBot, Bytespider, Meta-ExternalAgent, Amazonbot and more) are blocked by default. AI search bots, assistants fetching a page because someone asked about it, and SEO tools are allowed by default. Every bot has its own switch.

A blocked bot gets a 403 and is listed in `robots.txt`, added to another extension's robots.txt if you have one. Robots-only opt-outs like Google-Extended are included. There's an optional `ai.txt` too, and a button that checks your web server actually hands those files to Flarum. Many nginx setups answer them from disk.

![The Crawlers tab](https://raw.githubusercontent.com/ernestdefoe/bouncer/main/screenshots/admin-crawlers.png)

## Real search engines always get in, and fakes never do

Googlebot, Bingbot, Applebot, Yandex, Baidu, Yahoo, Seznam and Petal are verified the way the engines document. The reverse DNS name must belong to the engine and resolve back to the same address, and the answer is cached. A scraper that only *says* it's Googlebot is refused, even from a Google Cloud VM. A DNS hiccup is treated as "unknown", never as "fake", so a real crawler is never locked out by one.

## A challenge, not a captcha

A suspicious visitor gets a tiny self-contained page that loads no forum assets. Their browser works out a SHA-256 proof of work. That's about a tenth of a second on a laptop and well under a second on an older phone. Then they're back on the page they asked for, with a signed cookie for that browser and network. Scripts that can't run JavaScript stop there. Already using a Turnstile extension? Bouncer can show Turnstile instead, with the same keys.

What earns a challenge:

- HTTP libraries and headless browsers (curl, python-requests, Go, Scrapy, HeadlessChrome, Puppeteer, Playwright…);
- "browsers" missing the headers every real browser sends;
- visitors from about 55 cloud and hosting networks, refreshed weekly from RIPEstat;
- bursts above 60 requests a minute from one address, with a hard 429 above 300;
- everyone, when you flip the "under attack" switch.

## Made for the origin, not a CDN

Behind nginx or a container, you tell Bouncer which header your proxy sets. The admin page shows what your own request carried, so the choice is easy. `CF-Connecting-IP` is only believed from Cloudflare's published ranges, and IPv6 is counted per /64.

Bouncer never touches signed-in members, API keys or apps with a token, Flarum's own API calls, anything but GET and HEAD, or the server's own health checks. An ordinary guest costs one counter increment (APCu or your cache) and nothing else. A blocked scraper costs about a millisecond instead of a full page render.

## A log your staff can read

Allowed bots, challenges, answers, blocks and rate limits are logged with the address, user agent, path, reason and hit count. There's one row per address and reason every ten minutes, so a hammering bot writes a handful. The overview shows the week and the busiest bots. **Monitor only** mode logs what Bouncer *would* do, so you can watch it for a day before switching it on.

![The overview](https://raw.githubusercontent.com/ernestdefoe/bouncer/main/screenshots/admin-overview.png)

## Install

```
composer require ernestdefoe/bouncer
php flarum migrate
php flarum cache:clear
```

Run Flarum's scheduler from cron so the network list stays fresh. A snapshot ships with the extension, so it works from the first request.

Free and open source (MIT).

- Source: https://github.com/ernestdefoe/bouncer
- Try it: https://ernestdefoe.dev runs Bouncer. Point `curl` at it.
- Support: https://ernestdefoe.online/t/bouncer

☕ If you like the things I have built, consider [buying me a coffee](https://buymeacoffee.com/ernestdefoe).
