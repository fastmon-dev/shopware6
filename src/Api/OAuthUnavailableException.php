<?php declare(strict_types=1);

namespace Fastmon\Collector\Api;

/**
 * This fastmon instance does not serve the OAuth endpoints, or this shop cannot reach
 * them the way the grant requires.
 *
 * Distinct from every other failure because the answer is specific and the merchant can
 * act on it: paste an API key instead. Two things raise it - an instance with no
 * discovery document (older than the app connections), and a shop whose own address
 * cannot serve as a redirect target, which is the same dead end from the other side.
 */
final class OAuthUnavailableException extends FastmonApiException
{
}
