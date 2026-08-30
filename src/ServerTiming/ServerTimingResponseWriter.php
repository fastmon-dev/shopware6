<?php declare(strict_types=1);

namespace Fastmon\Collector\ServerTiming;

use Symfony\Component\HttpFoundation\Response;

/**
 * Puts our entries onto a response without disturbing anyone else's.
 *
 * `Server-Timing` is a shared header. A CDN, a reverse proxy, Shopware's own profiling
 * integration and another plugin may all have written to it, and the spec says the
 * browser concatenates every value it receives. So ours are appended, never set.
 *
 * With one exception, which is the reason this class exists at all: entries **we**
 * wrote on an earlier request can come back on a later one. A response served from a
 * full page cache - Shopware's internal one, Varnish, an nginx proxy_cache - carries
 * the headers it had when it was stored, so a cached page would report the database
 * time of whichever request happened to populate the cache, on every hit for as long
 * as the entry lives. Those numbers look plausible and are entirely wrong.
 *
 * Every entry this plugin emits is `fm-` prefixed, which makes them safe to recognise
 * and drop: the prefix is fastmon's own namespace, so no CDN and no other plugin emits
 * one. Stale entries go, everything else stays, and the operation is idempotent.
 */
final class ServerTimingResponseWriter
{
    public const HEADER = 'Server-Timing';

    /** The namespace that marks an entry as ours, and therefore as ours to replace. */
    private const OWNED_PREFIX = 'fm-';

    public function write(Response $response, string $value): void
    {
        $kept = $this->withoutOwnedEntries($response->headers->all(self::HEADER));

        if ($value !== '') {
            $kept[] = $value;
        }

        if ($kept === []) {
            $response->headers->remove(self::HEADER);

            return;
        }

        $response->headers->set(self::HEADER, $kept);
    }

    /**
     * Every existing header value with our entries removed, and values that end up
     * empty dropped entirely.
     *
     * Shopware 6.6 is the reason for that last part: its
     * `Profiling\Integration\ServerTiming` listener is registered unconditionally and
     * writes the header without checking it has anything to say, so every response
     * carries an empty value (fixed in 6.7.0.0). Keeping it would yield a leading
     * comma, which parses as a nameless metric.
     *
     * @param array<int, string|null> $values
     *
     * @return list<string>
     */
    private function withoutOwnedEntries(array $values): array
    {
        $kept = [];

        foreach ($values as $value) {
            if (!\is_string($value)) {
                continue;
            }

            $entries = [];

            foreach (explode(',', $value) as $entry) {
                $entry = trim($entry);

                if ($entry === '') {
                    continue;
                }

                // The name is everything up to the first `;`, and it is case insensitive
                // per the spec even though the collector lower-cases it anyway.
                $name = mb_strtolower(trim(explode(';', $entry, 2)[0]));

                if (str_starts_with($name, self::OWNED_PREFIX)) {
                    continue;
                }

                $entries[] = $entry;
            }

            if ($entries !== []) {
                $kept[] = implode(', ', $entries);
            }
        }

        return $kept;
    }
}
