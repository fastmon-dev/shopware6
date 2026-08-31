<?php declare(strict_types=1);

namespace Fastmon\Collector\Connection;

use Fastmon\Collector\Api\OAuthUnavailableException;
use Shopware\Core\DevOps\Environment\EnvironmentHelper;

/**
 * Where fastmon sends the merchant back to, and whether this shop can be sent back to at
 * all.
 *
 * ## Why the administration's own address
 *
 * The callback has to land somewhere the shop can turn into a token, and the
 * administration is the only address that is certain to work: it is where the merchant
 * already is, it is served over the same origin the plugin's admin API is on, and it
 * needs no sales-channel domain to exist. A storefront route would have to be resolved
 * against a sales channel, and the shop's canonical address is not required to be one of
 * those.
 *
 * The code that arrives there is handed to the plugin's admin API by the administration
 * itself, so the exchange - which needs the PKCE verifier, and the verifier never leaves
 * the server - still happens server to server.
 *
 * ## Why the browser's own location is trusted, but only that far
 *
 * The path of the administration can be changed, and some shops serve it from a different
 * host than `APP_URL`. The one party that knows the real answer is the browser that is
 * displaying it. So the administration sends its own location and it is accepted - but
 * only when its host and port match `APP_URL`, which is the shop's own declaration of
 * where it lives. That check is what keeps a redirect URI from being steered somewhere
 * else by whoever can call the admin API; without it, someone who can already write the
 * plugin configuration could register a client whose codes go to their own domain.
 *
 * `FASTMON_OAUTH_REDIRECT_URI` overrides the lot, for a shop behind a proxy that rewrites
 * the address the browser sees. An environment variable rather than a setting, because a
 * wrong value here is a connection that cannot complete, and the person who can fix that
 * is the one with shell access.
 */
final class RedirectUri
{
    private const ENV_OVERRIDE = 'FASTMON_OAUTH_REDIRECT_URI';

    /** Where Shopware serves the administration unless a shop moved it. */
    private const DEFAULT_ADMIN_PATH = '/admin';

    /**
     * fastmon accepts plain http only here, which is exactly right: a redirect over http
     * hands the authorization code to the network, and the only place that cannot be
     * intercepted is the machine itself.
     */
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1'];

    /**
     * The URI to register and to send the merchant back to.
     *
     * @param string $candidate the administration's own location, as the browser sees it
     *
     * @throws OAuthUnavailableException when this shop has no address that may carry a code
     */
    public function resolve(string $candidate): string
    {
        $override = $this->env(self::ENV_OVERRIDE);

        if ($override !== '') {
            return $this->requireUsable($override);
        }

        $appUrl = $this->normalise($this->env('APP_URL'));
        $offered = $this->normalise($candidate);

        // The browser's answer, but only when the shop agrees it is the shop.
        if ($offered !== '' && $appUrl !== '' && $this->sameOrigin($offered, $appUrl)) {
            return $this->requireUsable($offered);
        }

        if ($appUrl === '') {
            throw new OAuthUnavailableException(
                'This shop has no APP_URL, so fastmon has nowhere to send the merchant back to.'
            );
        }

        return $this->requireUsable(rtrim($appUrl, '/') . self::DEFAULT_ADMIN_PATH);
    }

    /**
     * Strip what a redirect URI may not carry, and nothing else.
     *
     * A query string or a fragment makes fastmon refuse the registration outright, and
     * both are ordinary on an administration URL - the callback itself arrives with one.
     * A trailing slash is dropped because the registered value has to match exactly and
     * `/admin` is the form Shopware links to.
     */
    private function normalise(string $uri): string
    {
        $uri = trim($uri);

        if ($uri === '') {
            return '';
        }

        $parts = parse_url($uri);

        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $path = rtrim($parts['path'] ?? '', '/');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $parts['scheme'] . '://' . $parts['host'] . $port . $path;
    }

    private function sameOrigin(string $one, string $other): bool
    {
        $a = parse_url($one);
        $b = parse_url($other);

        if (!\is_array($a) || !\is_array($b)) {
            return false;
        }

        return ($a['host'] ?? null) === ($b['host'] ?? null)
            && ($a['port'] ?? null) === ($b['port'] ?? null);
    }

    /**
     * The rule fastmon enforces on the other side, checked here so the merchant is told
     * what is wrong with their shop rather than being handed a registration error.
     */
    private function requireUsable(string $uri): string
    {
        $normalised = $this->normalise($uri);
        $parts = $normalised !== '' ? parse_url($normalised) : false;

        if (!\is_array($parts)) {
            throw new OAuthUnavailableException(
                'This shop\'s own address could not be read, so fastmon has nowhere to send the merchant back to.'
            );
        }

        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';

        if ($scheme !== 'https' && !($scheme === 'http' && \in_array($host, self::LOOPBACK_HOSTS, true))) {
            throw new OAuthUnavailableException(
                'The guided connection needs the administration to be reachable over https. '
                . 'This shop answers on ' . $normalised . '.'
            );
        }

        return $normalised;
    }

    private function env(string $key): string
    {
        $value = EnvironmentHelper::getVariable($key, '');

        return \is_scalar($value) ? trim((string) $value) : '';
    }
}
