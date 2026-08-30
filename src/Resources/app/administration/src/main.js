import './service/fastmon-collector.service';
import './mixin/fastmon-collector-error.mixin';
import './component/fastmon-collector-connection';
import './component/fastmon-collector-connection-device';
import './component/fastmon-collector-connection-provisioning';
import './component/fastmon-collector-collection';
import './component/fastmon-collector-server-timing';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);
