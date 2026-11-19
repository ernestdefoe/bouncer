import app from 'flarum/admin/app';

/** Bouncer's settings prefix. */
export const P = 'ernestdefoe-bouncer.';

/** One of Bouncer's admin strings. */
export const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`ernestdefoe-bouncer.admin.${key}`, params);

/** Every action the log records, in the order the admin page lists them. */
export const ACTIONS = ['allowed', 'challenged', 'solved', 'failed', 'blocked', 'limited', 'would_challenge', 'would_block', 'would_limit'];
