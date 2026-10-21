import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import BouncerPage from './components/BouncerPage';

export const extend = [new Extend.Admin().page(BouncerPage)];

app.initializers.add('ernestdefoe-bouncer', () => {});
