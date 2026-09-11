import { captureCallback } from './util/oauth-callback';
import './service/fastmon-collector.service';
import './mixin/fastmon-collector-error.mixin';
import './component/fastmon-collector-connection';
import './component/fastmon-collector-connection-authorize';
import './component/fastmon-collector-connection-provisioning';
import './component/fastmon-collector-collection';
import './component/fastmon-collector-server-timing';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);

// Before anything renders: fastmon's redirect lands on whatever administration page comes
// up first, and the authorization code has to be taken out of that URL and carried back to
// the panel that started it. Does nothing on every other boot.
captureCallback();
