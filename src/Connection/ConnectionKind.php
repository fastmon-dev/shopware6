<?php declare(strict_types=1);

namespace Fastmon\Collector\Connection;

/**
 * Which of the two credentials a shop holds, as the panel needs to know it.
 *
 * The panel says so because "disconnect" means something different for each: an app
 * connection is revoked on fastmon's side, a pasted key is only forgotten here. The
 * values are what the administration compares against, so they change together or not
 * at all.
 */
enum ConnectionKind: string
{
    /** Tokens from the consent flow: they expire, rotate, and can be revoked at fastmon. */
    case APP = 'app';

    /** A key pasted from the dashboard: it never expires and is only ever forgotten here. */
    case TOKEN = 'token';

    /** Nothing stored. */
    case NONE = '';

    public static function of(Credentials $credentials): self
    {
        if ($credentials->isAppConnection()) {
            return self::APP;
        }

        return $credentials->manualToken !== '' ? self::TOKEN : self::NONE;
    }
}
