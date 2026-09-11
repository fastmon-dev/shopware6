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
 * Whether it is sent is a setting (`serverTimingHost`, off by default), what it says
 * cannot be: plugin configuration lives in the database every node shares, so a
 * configured name would be identical on all of them. The value has to come from the
 * machine that answered, which is why `FASTMON_SERVER_NAME` overrides it and no field
 * does. On Kubernetes that variable is the right answer anyway, because the hostname is
 * a pod name that changes every deploy.
 *
 * Only the first label of the hostname: an FQDN would disclose domain structure and
 * internal naming, `web-01` discloses that the servers are called `web-01`.
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
