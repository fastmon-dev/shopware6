<?php declare(strict_types=1);

namespace Fastmon\Collector\Connection;

use Fastmon\Collector\Service\ConfigResolver;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Holds the in-flight `device_code` on the server and hands the administration an opaque
 * handle to poll with.
 *
 * The device code is the credential the shop redeems for a token, so it stays on the
 * shop. Sending it to the browser would work - the merchant is the one approving the
 * authorization anyway - but it would mean that anything able to read the admin page
 * could redeem the token itself the moment the merchant clicks approve, and carry it off.
 * With a handle, the same attacker can only ask this shop to finish a connection whose
 * token lands in `system_config` either way, which is no gain at all.
 *
 * ## Why `system_config` and not the object cache
 *
 * Because a cache is allowed to forget, and this must not. The obvious home was
 * `cache.object`, and it fails in practice: a Shopware installation is free to back its
 * app cache with an array adapter (dockware's dev image does exactly that, so nothing
 * survives the request that wrote it) or with APCu, which is per-process - the poll then
 * lands on a different PHP-FPM worker and finds nothing. Both turn the connect flow into
 * an authorization that expires the instant it starts, on a shop where every other part
 * of the plugin works.
 *
 * `system_config` is the one store a plugin can count on: it is shared across workers,
 * it is where the resulting token goes anyway, and it is written through the service the
 * rest of this plugin already uses. The entry is short-lived - it carries its own expiry
 * and is deleted the moment the authorization ends, successfully or not.
 *
 * One authorization at a time, which is what a shop connecting to one account needs.
 * Starting a second one replaces the first, so an abandoned attempt cannot linger.
 */
class DeviceAuthorizationSession
{
    private const KEY = ConfigResolver::DOMAIN . 'deviceAuthorization';

    /**
     * Handle length in bytes before hex encoding. 16 bytes is 128 bits, which is not a
     * secret anyone can usefully guess, and the value is worthless after a few minutes
     * anyway.
     */
    private const HANDLE_BYTES = 16;

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    /**
     * @return string the handle the administration polls with
     */
    public function start(string $deviceCode, int $expiresIn): string
    {
        $handle = bin2hex(random_bytes(self::HANDLE_BYTES));

        $this->systemConfigService->set(self::KEY, json_encode([
            'handle' => $handle,
            'deviceCode' => $deviceCode,
            // Outlive the authorization by a little, so an expired code is reported by
            // fastmon as `expired_token` - a message the merchant can act on - rather
            // than silently becoming an unknown handle here.
            'expiresAt' => time() + max(60, $expiresIn + 60),
        ], \JSON_THROW_ON_ERROR));

        return $handle;
    }

    /**
     * The device code behind a handle, or null when it expired, was finished, or never
     * existed.
     */
    public function resolve(string $handle): ?string
    {
        if (!$this->isWellFormed($handle)) {
            return null;
        }

        $stored = $this->read();

        if ($stored === null) {
            return null;
        }

        // Compared in constant time: the handle arrives over HTTP, and a timing oracle on
        // it would be an oracle on an in-flight authorization.
        if (!hash_equals((string) ($stored['handle'] ?? ''), $handle)) {
            return null;
        }

        if ((int) ($stored['expiresAt'] ?? 0) < time()) {
            $this->clear();

            return null;
        }

        $deviceCode = (string) ($stored['deviceCode'] ?? '');

        return $deviceCode !== '' ? $deviceCode : null;
    }

    /** Called once the authorization ended, successfully or not. */
    public function finish(string $handle): void
    {
        $stored = $this->read();

        // Only the authorization this handle belongs to: a stale poll arriving after a
        // new attempt started must not wipe the new one.
        if ($stored !== null && hash_equals((string) ($stored['handle'] ?? ''), $handle)) {
            $this->clear();
        }
    }

    /** Drop any in-flight authorization, whatever it is. Used when disconnecting. */
    public function abandon(): void
    {
        $this->clear();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(): ?array
    {
        $raw = $this->systemConfigService->get(self::KEY);

        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : null;
    }

    private function clear(): void
    {
        $this->systemConfigService->delete(self::KEY);
    }

    /**
     * Checked before the value is compared: a handle arrives over HTTP, and a malformed
     * one is not worth a lookup.
     */
    private function isWellFormed(string $handle): bool
    {
        return preg_match('/^[0-9a-f]{' . (self::HANDLE_BYTES * 2) . '}$/', $handle) === 1;
    }
}
