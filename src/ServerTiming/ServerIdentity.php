<?php declare(strict_types=1);

namespace Fastmon\Collector\ServerTiming;

/**
 * Which machine answered this request.
 *
 * In a cluster the interesting question is rarely "how fast is the shop" but "why is one
 * node slower than the others", and that is invisible unless the response says which node
 * it came from.
 *
 * ## Why this is not a setting
 *
 * Because a setting could not work. Plugin configuration lives in `system_config`, in the
 * database every node of the cluster shares - so a configured name would be the *same* on
 * all of them, which is precisely the opposite of what this is for. The value has to come
 * from the machine that answered.
 *
 * `gethostname()` is that value, and it is right on a fixed fleet. On an orchestrated one
 * it is the pod name: it changes on every deploy, so it groups nothing, and it is long
 * and random enough to be discarded outright - fastmon drops a `desc` of 24 characters or
 * more that is mostly hex-ish as an identifier, which `shopware-web-7d9f8b6c4d-x2k9p` is.
 * `FASTMON_SERVER_NAME` in the environment overrides it, because an environment variable
 * is per node the way a database row can never be: set it to `web-01` from the same
 * manifest that decides which node this is.
 */
class ServerIdentity
{
    /**
     * The collector caps `desc` at 32 characters and accepts
     * `^[a-zA-Z0-9 _.:/-]{1,32}$`. Trimming here rather than sending something that gets
     * discarded on arrival keeps "configured but never shows up" from being a mystery.
     */
    private const MAX_LENGTH = 32;

    /** Per-node override, for setups where the hostname is not a useful name. */
    private const ENV = 'FASTMON_SERVER_NAME';

    public function name(): string
    {
        $configured = $_SERVER[self::ENV] ?? getenv(self::ENV);
        $name = \is_string($configured) ? trim($configured) : '';

        if ($name === '') {
            $hostname = gethostname();
            $name = \is_string($hostname) ? $hostname : '';
        }

        // Anything outside the accepted set becomes a dash rather than being dropped:
        // `web01.fra.internal` should stay readable, and a stray character should not
        // cost the whole entry.
        $name = (string) preg_replace('/[^a-zA-Z0-9 _.:\/-]/', '-', $name);

        return mb_substr(trim($name), 0, self::MAX_LENGTH);
    }
}
