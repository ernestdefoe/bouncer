import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import type { ExtensionPageAttrs } from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

const P = 'ernestdefoe-bouncer.';
const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`ernestdefoe-bouncer.admin.${key}`, params);

type Tab = 'overview' | 'crawlers' | 'rules' | 'lists' | 'log';

interface Bot {
  key: string;
  name: string;
  operator: string;
  kind: 'training' | 'search' | 'assistant' | 'seo';
  blockedByDefault: boolean;
  robotsOnly: boolean;
}

interface Status {
  you: { ip: string; remote: string; public: boolean };
  networks: { updated: number; asns: number[]; source: string; ranges: number; configured: number[] };
  defaultAsns: Record<string, string>;
  days: { date: string; counts: Record<string, number> }[];
  bots: { bot: string; action: string; reason: string; hits: number }[];
  catalogue: Bot[];
  robots: string;
  turnstileAvailable: boolean;
  turnstileReady: boolean;
  apcu: boolean;
}

interface Row {
  id: number;
  ip: string;
  userAgent: string;
  action: string;
  reason: string;
  bot: string | null;
  path: string;
  hits: number;
  lastSeen: string;
}

interface Check {
  status: number;
  bouncer: boolean;
  error?: string;
}

const ACTIONS = ['allowed', 'challenged', 'solved', 'failed', 'blocked', 'limited', 'would_challenge', 'would_block', 'would_limit'];

/**
 * Bouncer's admin page: an overview of the week, the crawler list, the
 * rules, the allow/block lists and the staff log, as tabs. Settings save
 * through core's settings form; the overview and log come from Bouncer's
 * admin-only endpoints, loaded when their tab opens.
 */
export default class BouncerPage extends ExtensionPage<ExtensionPageAttrs> {
  tab: Tab = 'overview';
  status: Status | null = null;
  rows: Row[] = [];
  total = 0;
  filter = '';
  query = '';
  logLoading = false;
  refreshing = false;
  checks: Record<string, Check> | null = null;
  checking = false;
  searchTimer: number | undefined;

  oninit(vnode: Mithril.Vnode<ExtensionPageAttrs, this>) {
    super.oninit(vnode);
    this.loadStatus();
  }

  content() {
    const tabs: Tab[] = ['overview', 'crawlers', 'rules', 'lists', 'log'];

    return (
      <div className="ExtensionPage-settings BouncerPage">
        <div className="container">
          <nav className="BouncerPage-tabs" role="tablist">
            {tabs.map((tab) => (
              <button
                type="button"
                role="tab"
                aria-selected={this.tab === tab}
                className={'Button' + (this.tab === tab ? ' Button--primary' : '')}
                onclick={() => this.open(tab)}
              >
                {t('tabs.' + tab)}
              </button>
            ))}
          </nav>
          {this.tab === 'log' ? this.logTab() : this.settingsTab()}
        </div>
      </div>
    );
  }

  open(tab: Tab) {
    this.tab = tab;

    if (tab === 'log' && !this.rows.length) this.loadLog(true);
    if (tab === 'overview' || tab === 'crawlers') this.loadStatus();
  }

  settingsTab() {
    const body = { overview: () => this.overview(), crawlers: () => this.crawlers(), rules: () => this.rules(), lists: () => this.lists() }[
      this.tab as 'overview' | 'crawlers' | 'rules' | 'lists'
    ]();

    return (
      <form onsubmit={this.saveSettings.bind(this)}>
        {body}
        <div className="Form-group BouncerPage-save">{this.submitButton()}</div>
      </form>
    );
  }

  overview() {
    const s = this.status;

    return [
      this.buildSettingComponent({
        setting: P + 'mode',
        type: 'select',
        label: t('overview.mode_label'),
        help: t('overview.mode_help'),
        options: { on: t('overview.mode_on'), monitor: t('overview.mode_monitor') },
        default: 'on',
      }),
      !s ? (
        <LoadingIndicator />
      ) : (
        [
          <div className="Form-group">
            <label>{t('overview.week_heading')}</label>
            {this.week(s)}
          </div>,
          s.bots.length ? (
            <div className="Form-group">
              <label>{t('overview.bots_heading')}</label>
              <table className="BouncerPage-table">
                <tbody>
                  {s.bots.map((b) => (
                    <tr>
                      <td>{b.bot}</td>
                      <td>{t('overview.actions.' + b.action)}</td>
                      <td className="helpText">{t('log.reasons.' + b.reason)}</td>
                      <td className="BouncerPage-num">{b.hits.toLocaleString()}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : null,
          <div className="Form-group">
            <label>{t('overview.you_heading')}</label>
            <p className={'helpText' + (s.you.public ? '' : ' BouncerPage-warning')}>
              {t(s.you.public ? 'overview.you_public' : 'overview.you_private', { ip: s.you.ip })}
            </p>
          </div>,
          <div className="Form-group">
            <label>{t('overview.networks_heading')}</label>
            <p className="helpText">
              {t('overview.networks_info', {
                ranges: s.networks.ranges.toLocaleString(),
                asns: s.networks.asns.length,
                date: s.networks.updated ? humanTime(new Date(s.networks.updated * 1000)) : '—',
                source: t('overview.networks_source_' + s.networks.source),
              })}{' '}
              {t('overview.networks_cron')}
            </p>
            <Button className="Button" icon="fas fa-sync" loading={this.refreshing} onclick={() => this.refreshNetworks()}>
              {t('overview.refresh_button')}
            </Button>
            <p className="helpText">{t(s.apcu ? 'overview.apcu_on' : 'overview.apcu_off')}</p>
          </div>,
        ]
      ),
    ];
  }

  week(s: Status) {
    const used = ACTIONS.filter((a) => s.days.some((d) => d.counts[a]));

    if (!used.length) return <p className="helpText">{t('overview.week_empty')}</p>;

    return (
      <div className="BouncerPage-scroll">
        <table className="BouncerPage-table">
          <thead>
            <tr>
              <th></th>
              {s.days.map((d) => (
                <th className="BouncerPage-num">
                  {new Date(d.date + 'T00:00:00Z').toLocaleDateString(undefined, { weekday: 'short', timeZone: 'UTC' })}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {used.map((a) => (
              <tr>
                <th>{t('overview.actions.' + a)}</th>
                {s.days.map((d) => (
                  <td className="BouncerPage-num">{(d.counts[a] || 0).toLocaleString()}</td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    );
  }

  crawlers() {
    const s = this.status;
    const kinds: Bot['kind'][] = ['training', 'search', 'assistant', 'seo'];

    return [
      <p className="helpText">{t('crawlers.intro')}</p>,
      !s ? (
        <LoadingIndicator />
      ) : (
        kinds.map((kind) => (
          <fieldset className="Form-group BouncerPage-bots">
            <legend>{t('crawlers.kinds.' + kind)}</legend>
            <p className="helpText">{t('crawlers.kind_help.' + kind)}</p>
            {s.catalogue.filter((b) => b.kind === kind).map((b) => this.botRow(b))}
          </fieldset>
        ))
      ),
      <h3>{t('crawlers.robots_heading')}</h3>,
      this.buildSettingComponent({ setting: P + 'robots', type: 'switch', label: t('crawlers.robots_label'), help: t('crawlers.robots_help') }),
      this.buildSettingComponent({ setting: P + 'ai_txt', type: 'switch', label: t('crawlers.ai_txt_label') }),
      <div className="Form-group">
        <Button className="Button" icon="fas fa-search" loading={this.checking} onclick={() => this.checkRobots()}>
          {t('crawlers.check_button')}
        </Button>
        {this.checks
          ? Object.entries(this.checks)
              .filter(([key]) => this.on(key === 'robots' ? 'robots' : 'ai_txt'))
              .map(([key, c]) => {
                const file = key === 'robots' ? 'robots.txt' : 'ai.txt';

                return (
                  <p className={'helpText' + (c.bouncer ? '' : ' BouncerPage-warning')}>
                    {c.bouncer ? t('crawlers.check_ok', { file }) : t('crawlers.check_bad', { file, status: c.status || c.error || '?' })}
                  </p>
                );
              })
          : null}
      </div>,
      s ? (
        <div className="Form-group">
          <label>{t('crawlers.preview')}</label>
          <pre className="BouncerPage-pre">{s.robots}</pre>
        </div>
      ) : null,
    ];
  }

  botRow(bot: Bot) {
    const policies = this.policies();
    const blocked = policies[bot.key] ? policies[bot.key] === 'block' : bot.blockedByDefault;

    return (
      <div className="BouncerPage-bot">
        <span className="BouncerPage-botName">
          <strong>{bot.name}</strong> <span className="helpText">{bot.operator}</span>
          {bot.robotsOnly ? <span className="BouncerPage-tag">{t('crawlers.robots_only')}</span> : null}
        </span>
        <span className="ButtonGroup" role="group" aria-label={bot.name}>
          <button
            type="button"
            className={'Button Button--small' + (!blocked ? ' Button--primary' : '')}
            aria-pressed={!blocked}
            onclick={() => this.setPolicy(bot, 'allow')}
          >
            {t('crawlers.allowed')}
          </button>
          <button
            type="button"
            className={'Button Button--small' + (blocked ? ' Button--danger' : '')}
            aria-pressed={blocked}
            onclick={() => this.setPolicy(bot, 'block')}
          >
            {t('crawlers.blocked')}
          </button>
        </span>
      </div>
    );
  }

  /** A switch setting's saved or edited value. */
  on(key: string): boolean {
    return ['1', 'true', true, 1].includes(this.setting(P + key)() as any);
  }

  policies(): Record<string, string> {
    try {
      const value = JSON.parse(this.setting(P + 'bots', '{}')() || '{}');

      return value && typeof value === 'object' ? value : {};
    } catch {
      return {};
    }
  }

  setPolicy(bot: Bot, policy: 'allow' | 'block') {
    const policies = this.policies();

    // Only choices that differ from the default are stored.
    if ((policy === 'block') === bot.blockedByDefault) delete policies[bot.key];
    else policies[bot.key] = policy;

    this.setting(P + 'bots')(JSON.stringify(policies));
  }

  rules() {
    const s = this.status;
    const actions = (keys: string[]) => Object.fromEntries(keys.map((k) => [k, t('rules.action_' + k)]));
    const challengeTypes: Record<string, Mithril.Children> = { pow: t('rules.challenge_pow') };

    if (s?.turnstileAvailable || this.setting(P + 'challenge')() === 'turnstile') challengeTypes.turnstile = t('rules.challenge_turnstile');

    return [
      <h3>{t('rules.challenge_heading')}</h3>,
      this.buildSettingComponent({
        setting: P + 'challenge',
        type: 'select',
        label: t('rules.challenge_label'),
        options: challengeTypes,
        default: 'pow',
      }),
      s && !s.turnstileReady ? <p className="helpText BouncerPage-warning">{t('rules.turnstile_missing')}</p> : null,
      this.buildSettingComponent({
        setting: P + 'difficulty',
        type: 'number',
        min: 12,
        max: 22,
        label: t('rules.difficulty_label'),
        help: t('rules.difficulty_help'),
      }),
      this.buildSettingComponent({ setting: P + 'pass_days', type: 'number', min: 1, max: 90, label: t('rules.pass_days_label') }),
      this.buildSettingComponent({ setting: P + 'everyone', type: 'switch', label: t('rules.everyone_label'), help: t('rules.everyone_help') }),
      <h3>{t('rules.signals_heading')}</h3>,
      this.buildSettingComponent({
        setting: P + 'tools',
        type: 'select',
        label: t('rules.tools_label'),
        help: t('rules.tools_help'),
        options: actions(['challenge', 'block', 'off']),
        default: 'challenge',
      }),
      this.buildSettingComponent({
        setting: P + 'headers',
        type: 'select',
        label: t('rules.headers_label'),
        help: t('rules.headers_help'),
        options: actions(['challenge', 'off']),
        default: 'challenge',
      }),
      this.buildSettingComponent({
        setting: P + 'datacentre',
        type: 'select',
        label: t('rules.datacentre_label'),
        help: t('rules.datacentre_help'),
        options: actions(['challenge', 'off']),
        default: 'challenge',
      }),
      this.buildSettingComponent({
        setting: P + 'fake_engines',
        type: 'select',
        label: t('rules.fake_engines_label'),
        help: t('rules.fake_engines_help'),
        options: actions(['block', 'challenge', 'off']),
        default: 'block',
      }),
      <h3>{t('rules.burst_heading')}</h3>,
      <p className="helpText">{t('rules.burst_help')}</p>,
      this.buildSettingComponent({ setting: P + 'burst_challenge', type: 'number', min: 0, label: t('rules.burst_challenge_label') }),
      this.buildSettingComponent({ setting: P + 'burst_block', type: 'number', min: 0, label: t('rules.burst_block_label') }),
      this.buildSettingComponent({ setting: P + 'api', type: 'switch', label: t('rules.api_label'), help: t('rules.api_help') }),
      this.buildSettingComponent({ setting: P + 'log_days', type: 'number', min: 1, max: 365, label: t('rules.log_days_label') }),
    ];
  }

  lists() {
    const area = (key: string, label: string, help: string, placeholder = '') =>
      this.buildSettingComponent({ setting: P + key, type: 'textarea', label: t(label), help: t(help), rows: 4, placeholder });
    const defaults = this.status?.defaultAsns;

    return [
      area('allow_ips', 'lists.allow_ips_label', 'lists.ips_help', '203.0.113.7\n198.51.100.0/24'),
      area('block_ips', 'lists.block_ips_label', 'lists.ips_help'),
      area('allow_agents', 'lists.allow_agents_label', 'lists.agents_help'),
      area('block_agents', 'lists.block_agents_label', 'lists.agents_help'),
      area('exempt_paths', 'lists.exempt_label', 'lists.exempt_help'),
      area('trusted_proxies', 'lists.proxies_label', 'lists.proxies_help'),
      area('extra_asns', 'lists.extra_asns_label', 'lists.asns_help', 'AS12345, AS67890'),
      area('ignore_asns', 'lists.ignore_asns_label', 'lists.asns_help'),
      defaults ? (
        <details className="Form-group">
          <summary>{t('lists.default_asns')}</summary>
          <p className="helpText">
            {Object.entries(defaults)
              .map(([asn, name]) => `AS${asn} ${name}`)
              .join(' · ')}
          </p>
        </details>
      ) : null,
    ];
  }

  logTab() {
    return (
      <div className="BouncerPage-log">
        <div className="BouncerPage-logBar">
          <select
            className="FormControl"
            value={this.filter}
            onchange={(e: Event) => {
              this.filter = (e.target as HTMLSelectElement).value;
              this.loadLog(true);
            }}
          >
            <option value="">{t('log.filter_all')}</option>
            {ACTIONS.map((a) => (
              <option value={a}>{t('overview.actions.' + a)}</option>
            ))}
          </select>
          <input
            className="FormControl"
            type="search"
            placeholder={t('log.search_placeholder')}
            value={this.query}
            oninput={(e: Event) => {
              this.query = (e.target as HTMLInputElement).value;
              window.clearTimeout(this.searchTimer);
              this.searchTimer = window.setTimeout(() => this.loadLog(true), 300);
            }}
          />
          <Button className="Button Button--danger" icon="fas fa-trash" onclick={() => this.clearLog()}>
            {t('log.clear_button')}
          </Button>
        </div>
        {this.rows.length ? (
          <div className="BouncerPage-scroll">
            <table className="BouncerPage-table BouncerPage-logTable">
              <thead>
                <tr>
                  {['when', 'action', 'reason', 'address', 'agent', 'path', 'hits'].map((c) => (
                    <th>{t('log.columns.' + c)}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {this.rows.map((r) => (
                  <tr>
                    <td className="BouncerPage-when">{humanTime(new Date(r.lastSeen))}</td>
                    <td>
                      <span className={'BouncerPage-action BouncerPage-action--' + r.action}>{t('overview.actions.' + r.action)}</span>
                    </td>
                    <td>
                      {t('log.reasons.' + r.reason)}
                      {r.bot ? <div className="helpText">{r.bot}</div> : null}
                    </td>
                    <td>
                      <code>{r.ip}</code>
                    </td>
                    <td className="BouncerPage-agent" title={r.userAgent}>
                      {r.userAgent}
                    </td>
                    <td className="BouncerPage-path" title={r.path}>
                      {r.path}
                    </td>
                    <td className="BouncerPage-num">{r.hits.toLocaleString()}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : this.logLoading ? null : (
          <p className="helpText">{t('log.empty')}</p>
        )}
        {this.logLoading ? <LoadingIndicator /> : null}
        {!this.logLoading && this.rows.length < this.total ? (
          <Button className="Button" onclick={() => this.loadLog(false)}>
            {t('log.more')}
          </Button>
        ) : null}
      </div>
    );
  }

  loadStatus() {
    app
      .request<Status>({ method: 'GET', url: app.forum.attribute('apiUrl') + '/bouncer/status' })
      .then((status) => {
        this.status = status;
        m.redraw();
      })
      .catch(() => {});
  }

  loadLog(reset: boolean) {
    this.logLoading = true;
    if (reset) this.rows = [];

    app
      .request<{ rows: Row[]; total: number }>({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/bouncer/log',
        params: { action: this.filter, q: this.query, offset: this.rows.length },
      })
      .then((result) => {
        this.rows = this.rows.concat(result.rows);
        this.total = result.total;
      })
      .finally(() => {
        this.logLoading = false;
        m.redraw();
      });
  }

  clearLog() {
    if (!confirm(extractText(t('log.clear_confirm')))) return;

    app.request({ method: 'DELETE', url: app.forum.attribute('apiUrl') + '/bouncer/log' }).then(() => {
      this.rows = [];
      this.total = 0;
      m.redraw();
    });
  }

  refreshNetworks() {
    this.refreshing = true;

    app
      .request<{ error?: string }>({ method: 'POST', url: app.forum.attribute('apiUrl') + '/bouncer/networks', errorHandler: () => {} })
      .then(() => {
        app.alerts.show({ type: 'success' }, t('overview.refresh_done'));
        this.loadStatus();
      })
      .catch((e: any) => {
        app.alerts.show({ type: 'error' }, t('overview.refresh_failed', { error: e?.response?.error || e?.status || '?' }));
      })
      .finally(() => {
        this.refreshing = false;
        m.redraw();
      });
  }

  checkRobots() {
    this.checking = true;

    app
      .request<Record<string, Check>>({ method: 'GET', url: app.forum.attribute('apiUrl') + '/bouncer/robots-check' })
      .then((checks) => (this.checks = checks))
      .finally(() => {
        this.checking = false;
        m.redraw();
      });
  }

  onsaved() {
    super.onsaved();
    this.loadStatus();
  }
}
