<?php declare(strict_types=1);

namespace Fastmon\Collector\Twig;

use Fastmon\Collector\Service\ConfigResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Gives the storefront templates the one value they cannot read with `config()`.
 *
 * Everything else the snippets need is a stored setting, so `config()` reaches it
 * directly. The script base is not stored: it is derived from the collection mode, so
 * that the script and the beacon can never end up on different hosts. Deriving it again
 * in Twig would be a second copy of that rule, free to drift from the first the day
 * someone adds a fourth mode.
 */
final class CollectorExtension extends AbstractExtension
{
    public function __construct(
        private readonly ConfigResolver $configResolver,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('fastmon_script_base', $this->scriptBase(...)),
        ];
    }

    /**
     * Base for `/s/…` and `/c/…`: empty in `relative` (same-origin), the merchant's host
     * in `custom`, fastmon's in `default`.
     *
     * No sales-channel argument: one fastmon application covers every sales channel, so
     * the mode is shop-wide by construction.
     */
    public function scriptBase(): string
    {
        return $this->configResolver->storefront(null)->scriptBaseUrl;
    }
}
