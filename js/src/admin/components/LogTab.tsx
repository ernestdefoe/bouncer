import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { ACTIONS, t } from '../common';

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

/**
 * The staff log tab: newest first from /api/bouncer-log, filtered by action
 * or a part of an address, user agent or bot name, 50 rows at a time.
 */
export default class LogTab extends Component {
  rows: Row[] = [];
  total = 0;
  filter = '';
  query = '';
  loading = false;
  searchTimer: number | undefined;
  /** Bumped by every load, so a slower, older answer never lands on a newer one. */
  generation = 0;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.load(true);
  }

  onremove() {
    window.clearTimeout(this.searchTimer);
  }

  view() {
    return (
      <div className="BouncerPage-log">
        {this.bar()}
        {this.rows.length ? this.table() : this.loading ? null : <p className="helpText">{t('log.empty')}</p>}
        {this.loading ? <LoadingIndicator /> : null}
        {!this.loading && this.rows.length < this.total ? (
          <Button className="Button" onclick={() => this.load(false)}>
            {t('log.more')}
          </Button>
        ) : null}
      </div>
    );
  }

  bar() {
    return (
      <div className="BouncerPage-logBar">
        <select
          className="FormControl"
          value={this.filter}
          onchange={(e: Event) => {
            this.filter = (e.target as HTMLSelectElement).value;
            this.load(true);
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
            this.searchTimer = window.setTimeout(() => this.load(true), 300);
          }}
        />
        <Button className="Button Button--danger" icon="fas fa-trash" onclick={() => this.clear()}>
          {t('log.clear_button')}
        </Button>
      </div>
    );
  }

  table() {
    return (
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
    );
  }

  load(reset: boolean) {
    const generation = ++this.generation;
    this.loading = true;
    if (reset) this.rows = [];

    const filter: Record<string, string> = {};
    if (this.filter) filter.action = this.filter;
    if (this.query.trim()) filter.q = this.query.trim();

    app
      .request<{ data: { id: string; attributes: Omit<Row, 'id'> }[]; meta?: { page?: { total?: number } } }>({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/bouncer-log',
        params: { filter, page: { offset: this.rows.length } },
      })
      .then((result) => {
        if (generation !== this.generation) return;
        this.rows = this.rows.concat(result.data.map((r) => ({ id: Number(r.id), ...r.attributes })));
        this.total = result.meta?.page?.total ?? this.rows.length;
      })
      .catch(() => {
        /* app.request already showed the alert */
      })
      .finally(() => {
        if (generation !== this.generation) return;
        this.loading = false;
        m.redraw();
      });
  }

  clear() {
    if (!confirm(extractText(t('log.clear_confirm')))) return;

    app
      .request({ method: 'DELETE', url: app.forum.attribute('apiUrl') + '/bouncer-log' })
      .then(() => {
        this.rows = [];
        this.total = 0;
        m.redraw();
      })
      .catch(() => {
        /* app.request already showed the alert */
      });
  }
}
