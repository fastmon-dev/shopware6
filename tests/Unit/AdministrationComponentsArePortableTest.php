<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every `mt-*` element in the plugin's administration templates has to be registered by
 * every Shopware the plugin supports.
 *
 * The Meteor component library ships far more components than the administration hands
 * to Vue, and the list it does hand over is different per Shopware release: 6.6
 * registers `MtExternalLink` and no `MtLink`, 6.7 registers `MtLink` and no
 * `MtExternalLink`. An element outside that list is not an error anywhere - Vue resolves
 * nothing and renders nothing - so the panel loses a control in silence, on the one
 * branch nobody develops against. That is how the two links into the fastmon dashboard
 * were missing on 6.6 while looking correct on 6.7.
 *
 * The lists below are copied from `vue.adapter.ts` of each branch. They are a snapshot,
 * which is the point: a component that appears in a later 6.7 patch is still not
 * available on 6.6, and this test is what says so before a merchant does.
 */
final class AdministrationComponentsArePortableTest extends TestCase
{
    /** Registered in 6.6.10.3, `src/app/adapter/view/vue.adapter.ts`. */
    private const SHOPWARE_66 = [
        'mt-banner', 'mt-loader', 'mt-progress-bar', 'mt-button', 'mt-checkbox',
        'mt-colorpicker', 'mt-datepicker', 'mt-email-field', 'mt-external-link',
        'mt-number-field', 'mt-password-field', 'mt-select', 'mt-slider', 'mt-switch',
        'mt-text-field', 'mt-textarea', 'mt-url-field', 'mt-icon', 'mt-data-table',
        'mt-pagination', 'mt-skeleton-bar', 'mt-toast', 'mt-floating-ui', 'mt-popover',
    ];

    /** Registered in 6.7.13.1, same file. */
    private const SHOPWARE_67 = [
        'mt-avatar', 'mt-banner', 'mt-loader', 'mt-progress-bar', 'mt-button',
        'mt-checkbox', 'mt-email-field', 'mt-empty-state', 'mt-number-field',
        'mt-password-field', 'mt-select', 'mt-slider', 'mt-switch', 'mt-text-field',
        'mt-textarea', 'mt-icon', 'mt-pagination', 'mt-skeleton-bar', 'mt-toast',
        'mt-floating-ui', 'mt-text-editor-toolbar-button', 'mt-modal', 'mt-modal-root',
        'mt-modal-close', 'mt-modal-trigger', 'mt-modal-action', 'mt-url-field',
        'mt-search', 'mt-link', 'mt-unit-field', 'mt-snackbar', 'mt-badge',
        'mt-promo-badge', 'mt-action-menu', 'mt-action-menu-item', 'mt-action-menu-group',
    ];

    public function testEveryMeteorComponentUsedIsRegisteredOnBothBranches(): void
    {
        $portable = array_intersect(self::SHOPWARE_66, self::SHOPWARE_67);
        $used = $this->meteorComponentsInTemplates();

        static::assertNotEmpty($used, 'No mt-* element found - has the template layout changed?');

        foreach ($used as $component => $files) {
            static::assertContains(
                $component,
                $portable,
                sprintf(
                    '<%s> is used in %s but is not registered by every supported Shopware. '
                    . 'On a branch that does not register it, Vue renders nothing and the control disappears.',
                    $component,
                    implode(', ', $files)
                )
            );
        }
    }

    /**
     * Every component template, which is every `.html.twig` the administration module
     * has: one per component directory.
     *
     * @return array<string, list<string>> component name to the templates using it
     */
    private function meteorComponentsInTemplates(): array
    {
        $root = \dirname(__DIR__, 2) . '/src/Resources/app/administration/src';
        $paths = glob($root . '/component/*/*.html.twig');

        static::assertIsArray($paths, 'Could not read the administration templates.');

        $used = [];

        foreach ($paths as $path) {
            preg_match_all('/<(mt-[a-z0-9-]+)/', (string) file_get_contents($path), $matches);

            foreach ($matches[1] as $component) {
                $used[$component][] = basename($path);
                $used[$component] = array_values(array_unique($used[$component]));
            }
        }

        ksort($used);

        return $used;
    }
}
