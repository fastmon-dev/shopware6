<?php declare(strict_types=1);

namespace Fastmon\Collector\Api;

/**
 * Where a device authorization stands, per RFC 8628 §3.5.
 *
 * Only the states the shop keeps polling through are modelled. The terminal failures -
 * `access_denied` and `expired_token` - are exceptions instead, because there is nothing
 * left to poll for and the admin module has to stop rather than decide to.
 */
enum DevicePollStatus: string
{
    /** The merchant has not finished approving yet. Keep polling at the same interval. */
    case PENDING = 'pending';

    /** Polling too fast. Add five seconds to the interval, per the RFC, then continue. */
    case SLOW_DOWN = 'slow_down';

    /** Approved: a token was issued. */
    case COMPLETE = 'complete';
}
