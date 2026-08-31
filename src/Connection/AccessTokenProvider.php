<?php declare(strict_types=1);

namespace Fastmon\Collector\Connection;

use Fastmon\Collector\Api\FastmonCredentialExpiredException;
use Fastmon\Collector\Api\FastmonOAuthClient;
use Fastmon\Collector\Api\FastmonOAuthException;
use Fastmon\Collector\Api\FastmonUnauthorizedException;
use Fastmon\Collector\Service\ConfigResolver;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

/**
 * Hands out a usable access token, and keeps the connection alive while doing it.
 *
 * An app connection is a pair: an access token good for fifteen minutes, and a refresh
 * token that buys the next pair. Everything that makes this more than two lines of code
 * follows from one property of that refresh token - **it works exactly once**, and
 * presenting a spent one ends the connection. fastmon does that deliberately: a token
 * offered twice means two parties hold it, and it cannot know which of them is the thief.
 *
 * So this class exists to make sure the shop is never the party that presents one twice.
 *
 * - **One refresh at a time.** The administration panel opens several admin API calls at
 *   once, and two of them finding an expired token a millisecond apart would send the same
 *   refresh token twice - disconnecting a shop that did nothing wrong. A lock serialises
 *   them, and the one that waited re-reads what the winner stored instead of refreshing
 *   again.
 * - **Stored before used.** The successor is written to `system_config` before the new
 *   access token leaves this class (see `ConnectionStore::saveTokens()`), so a request
 *   that dies mid-flight loses an access token rather than the connection.
 * - **`invalid_grant` is final.** Spent, expired, revoked in the dashboard, or reuse
 *   already detected - fastmon does not say which, and none of them can be retried. The
 *   credential is dropped and the merchant reconnects; retrying would be the second
 *   presentation.
 *
 * A pasted API key passes straight through: it does not expire and there is nothing to
 * rotate, which is exactly why that fallback still exists.
 */
#[WithMonologChannel('fastmon_collector')]
final class AccessTokenProvider
{
    /**
     * Refresh this long before the token actually expires. Removes the case where a token
     * is checked, found valid, and has expired by the time fastmon reads it; at a
     * fifteen-minute lifetime, spending a minute of it costs nothing.
     */
    private const EXPIRY_SKEW_SECONDS = 60;

    private const LOCK_KEY = 'fastmon_collector.token_refresh';

    /**
     * Long enough for a token request against a slow backend (the HTTP call itself is
     * capped at ten seconds), short enough that a worker killed mid-refresh does not
     * block the next attempt for a noticeable time.
     */
    private const LOCK_TTL_SECONDS = 30;

    public function __construct(
        private readonly FastmonOAuthClient $oauth,
        private readonly ConnectionStore $store,
        private readonly ConfigResolver $config,
        // By service id: `lock.factory` is what Shopware configures for the shop, and by
        // type alone autowiring would have no definition to pick.
        #[Autowire(service: 'lock.factory')]
        private readonly LockFactory $locks,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * A token to authenticate an API call with, refreshed first if it is about to expire.
     *
     * @throws FastmonUnauthorizedException when the shop has never connected
     * @throws FastmonCredentialExpiredException when the connection ended on fastmon's side
     */
    public function token(): string
    {
        $credentials = $this->store->credentials();

        if ($credentials->isAppConnection() && !$credentials->hasFreshAccessToken(self::EXPIRY_SKEW_SECONDS)) {
            return $this->refresh('');
        }

        if ($credentials->accessToken !== '') {
            return $credentials->accessToken;
        }

        if ($credentials->manualToken !== '') {
            return $credentials->manualToken;
        }

        throw new FastmonUnauthorizedException('This shop is not connected to fastmon yet.');
    }

    /**
     * Run one API call with a token, and retry it once on a token fastmon rejected.
     *
     * The retry is what makes the fifteen-minute lifetime invisible: an access token can
     * expire between two calls of the same panel load, and a clock a few minutes off makes
     * the proactive refresh miss. Only an app connection can retry - a pasted key that was
     * rejected will be rejected again, and a second call would only be a second failure.
     *
     * @template T
     *
     * @param callable(string): T $call
     *
     * @return T
     */
    public function call(callable $call): mixed
    {
        $token = $this->token();

        try {
            return $call($token);
        } catch (FastmonUnauthorizedException $e) {
            if (!$this->store->credentials()->isAppConnection()) {
                throw $e;
            }

            $this->logger->info('fastmon: access token was rejected, refreshing and retrying once');

            return $call($this->refresh($token));
        }
    }

    /**
     * Rotate the refresh token and return the new access token.
     *
     * `$stale` is the access token that just failed, or an empty string when the caller
     * only found an expired one. It is what tells this apart from the token another
     * process stored while we waited for the lock: if what is in the store now is neither
     * stale nor expiring, the refresh already happened and spending our own token would be
     * the second presentation.
     */
    private function refresh(string $stale): string
    {
        $lock = $this->locks->createLock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);
        $lock->acquire(true);

        try {
            $credentials = $this->store->credentials();

            if ($credentials->accessToken !== $stale && $credentials->hasFreshAccessToken(self::EXPIRY_SKEW_SECONDS)) {
                return $credentials->accessToken;
            }

            if (!$credentials->isAppConnection()) {
                throw new FastmonUnauthorizedException(
                    'This shop has no fastmon connection that can be renewed. Please connect again.'
                );
            }

            return $this->rotate($credentials);
        } finally {
            $lock->release();
        }
    }

    private function rotate(Credentials $credentials): string
    {
        try {
            $tokens = $this->oauth->refresh(
                $this->config->apiBaseUrl(),
                $credentials->clientId,
                $credentials->refreshToken
            );
        } catch (FastmonOAuthException $e) {
            // The one error that is not worth another attempt, under any of its four
            // meanings. Keeping the token would leave the panel retrying a connection that
            // is already gone, and every retry looks like theft from fastmon's side.
            if ($e->error === 'invalid_grant') {
                $this->store->clearCredentials();
                $this->logger->warning('fastmon: the connection ended on fastmon\'s side: ' . $e->getMessage());

                throw new FastmonCredentialExpiredException(
                    'The fastmon connection ended. Please connect again.'
                );
            }

            throw $e;
        }

        $this->store->saveTokens($tokens);

        // A re-approval can narrow the grant, and the organization is restated on every
        // refresh - which is the cheapest way to keep the name in the panel true. Only
        // ever an update, never a blanking: an instance that answers without the name must
        // not cost the panel the one it already had.
        if ($tokens->organizationId !== '' && $tokens->organizationName !== '') {
            $this->store->saveOrganization($tokens->organizationId, $tokens->organizationName);
        }

        return $tokens->accessToken;
    }
}
