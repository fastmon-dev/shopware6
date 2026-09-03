<?php declare(strict_types=1);

namespace Fastmon\Collector\ServerTiming;

use Shopware\Core\DevOps\Environment\EnvironmentHelper;

/**
 * Which machine answered this request.
 *
 * In a cluster the interesting question is rarely "how fast is the shop" but "why is one
 * node slower than the others", and that is invisible unless the response says which node
 * it came from.
 *
 * ## Why the name is not a setting
 *
 * Whether it is sent is one (`serverTimingHost`, off by default). What it says cannot
 * be, because a setting could not work. Plugin configuration lives in `system_config`, in the
 * database every node of the cluster shares - so a configured name would be the *same* on
 * all of them, which is precisely the opposite of what this is for. The value has to come
 * from the machine that answered.
 *
 * Only the first label of the hostname: an FQDN would disclose domain structure and
 * internal naming, `web-01` discloses that the servers are called `web-01`.
 *
 * `FASTMON_SERVER_NAME` overrides it, because an environment variable is per node the way
 * a database row can never be - and it is the right move on Kubernetes, where the
 * hostname is a pod name that changes every deploy and groups nothing.
 */
final class ServerIdentity
{
    /**
     * The collector caps `desc` at 32 characters and accepts
     * `^[a-zA-Z0-9 _.:/-]{1,32}$`. Trimming here rather than sending something that gets
     * discarded on arrival keeps "configured but never shows up" from being a mystery.
     */
    private const MAX_LENGTH = 32;

    /** Per-node override, for setups where the hostname is not a useful name. */
    private const ENV = 'FASTMON_SERVER_NAME';

    /**
     * Resolved once per process. The answer cannot change while PHP is running - neither
     * the hostname nor the environment do - and this is read on every HTML response.
     */
    private ?string $name = null;

    public function name(): string
    {
        return $this->name ??= $this->resolve();
    }

    private function resolve(): string
    {
        $configured = EnvironmentHelper::getVariable(self::ENV, '');
        $name = \is_string($configured) ? trim($configured) : '';

        if ($name === '') {
            $hostname = gethostname();
            $label = \is_string($hostname) ? strtok($hostname, '.') : '';
            $name = \is_string($label) ? $label : '';
        }

        // Anything outside the accepted set becomes a dash rather than being dropped:
        // `web01.fra.internal` should stay readable, and a stray character should not
        // cost the whole entry.
        $name = (string) preg_replace('/[^a-zA-Z0-9 _.:\/-]/', '-', $name);

        return mb_substr(trim($name), 0, self::MAX_LENGTH);
    }
}
