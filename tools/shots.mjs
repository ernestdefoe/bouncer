/**
 * Screenshots for the README and the announcement, on stock Flarum's default
 * theme, and a console/HTTP error sweep of the admin page:
 *   BASE=http://127.0.0.1:8471 TOKEN=<admin remember token> COOKIE=flarum node tools/shots.mjs
 *   (OUT=<dir> to save elsewhere, ADMIN_ONLY=1 to skip the challenge shots)
 *
 * Headless Chrome says "HeadlessChrome" in its user agent, which Bouncer
 * challenges, so the shots use an ordinary Chrome user agent.
 */
import puppeteer from '../../convoro2/node_modules/puppeteer-core/lib/puppeteer/puppeteer-core.js';
import { mkdir } from 'node:fs/promises';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE = process.env.BASE || 'http://127.0.0.1:8471';
const TOKEN = process.env.TOKEN;
const COOKIE = process.env.COOKIE || 'flarum';
const OUT = process.env.OUT || `${dirname(fileURLToPath(import.meta.url))}/../screenshots`;
const ADMIN_ONLY = !!process.env.ADMIN_ONLY;
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
const DESKTOP = { width: 1280, height: 900, deviceScaleFactor: 2 };
const PHONE = { width: 390, height: 844, deviceScaleFactor: 3, isMobile: true, hasTouch: true };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

await mkdir(OUT, { recursive: true });
const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--hide-scrollbars', '--force-color-profile=srgb'] });
const errors = [];

async function context(admin, viewport = DESKTOP, dark = false) {
  const ctx = await browser.createBrowserContext();
  const page = await ctx.newPage();
  page.on('pageerror', (e) => errors.push(String(e)));
  // The challenge pages answer 403 on purpose; only the admin pages' console counts.
  if (admin) page.on('console', (m) => m.type() === 'error' && errors.push('console: ' + m.text()));
  page.on('response', (r) => r.status() >= 400 && !r.url().endsWith('favicon.ico') && !r.url().includes('/bouncer/verify') && r.status() !== 403 && errors.push(`${r.status()} ${r.url()}`));
  await page.setUserAgent(UA);
  // XFF=<address> plays a reverse proxy, for a forum set to read X-Forwarded-For.
  await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-GB,en;q=0.9', ...(process.env.XFF ? { 'X-Forwarded-For': process.env.XFF } : {}) });
  await page.setViewport(viewport);
  await page.emulateMediaFeatures([
    { name: 'prefers-reduced-motion', value: 'reduce' },
    { name: 'prefers-color-scheme', value: dark ? 'dark' : 'light' },
  ]);
  if (admin) await page.setCookie({ name: `${COOKIE}_remember`, value: TOKEN, domain: new URL(BASE).hostname, path: '/', httpOnly: true });
  return page;
}

async function shot(page, name, clip) {
  await sleep(400);
  await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: !clip, clip });
  console.log('saved', name);
}

// The challenge, frozen part-way: timers are stubbed so the proof of work
// never starts, and the bar is set to show progress.
for (const [name, viewport, dark] of ADMIN_ONLY ? [] : [['challenge', DESKTOP, false], ['challenge-dark', DESKTOP, true], ['challenge-phone', PHONE, false]]) {
  const page = await context(false, viewport, dark);
  await page.evaluateOnNewDocument(() => {
    window.setTimeout = () => 0;
  });
  await page.setUserAgent(UA + ' (bouncer screenshot, curl/8)');
  await page.goto(BASE + '/', { waitUntil: 'load' });
  await page.evaluate(() => (document.getElementById('bar').style.width = '45%'));
  await shot(page, name, viewport === PHONE ? null : { x: 290, y: 250, width: 700, height: 400 });
  await page.close();
}

// A real visitor solving it: measured end to end.
if (!ADMIN_ONLY) {
  const page = await context(false);
  const started = Date.now();
  await page.goto(BASE + '/?bouncer-shot=1', { waitUntil: 'load' });
  const challenged = await page.$('form#f');
  if (challenged) await page.waitForNavigation({ waitUntil: 'load', timeout: 20000 });
  console.log('challenge shown:', !!challenged, 'forum loaded after', Date.now() - started, 'ms');
  await page.close();
}

if (TOKEN) {
  const page = await context(true);
  await page.goto(BASE + '/admin#/extension/ernestdefoe-bouncer', { waitUntil: 'networkidle0' });
  await page.waitForSelector('.BouncerPage-tabs');
  await sleep(800);
  await shot(page, 'admin-overview');

  for (const [i, name] of [[1, 'crawlers'], [2, 'rules'], [3, 'lists'], [4, 'log']]) {
    await page.click(`.BouncerPage-tabs button:nth-child(${i + 1})`);
    await sleep(900);
    await shot(page, 'admin-' + name);
  }

  // The robots check button, on the crawlers tab.
  await page.click('.BouncerPage-tabs button:nth-child(2)');
  await sleep(500);
  const [check] = await page.$$('xpath/.//button[contains(., "Check what crawlers see")]');
  if (check) {
    await check.click();
    await sleep(2500);
    console.log('robots check:', await page.$$eval('.BouncerPage .helpText', (els) => els.map((e) => e.textContent).filter((t) => t.includes('.txt')).join(' | ')));
  }

  const phone = await context(true, PHONE);
  await phone.goto(BASE + '/admin#/extension/ernestdefoe-bouncer', { waitUntil: 'networkidle0' });
  await phone.waitForSelector('.BouncerPage-tabs');
  await phone.click('.BouncerPage-tabs button:nth-child(5)');
  await sleep(900);
  const overflow = await phone.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  console.log('phone horizontal overflow (px):', overflow);
  await shot(phone, 'admin-log-phone');
}

console.log(errors.length ? 'ERRORS:\n' + errors.join('\n') : 'no console or HTTP errors');
await browser.close();
