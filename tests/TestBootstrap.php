<?php declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Shopware\Core\TestBootstrapper;

/*
 * Reached in three ways, and it has to work in all of them.
 *
 *  - With the plugin's own PHPUnit (`composer install` in this directory, then
 *    `vendor/bin/phpunit`): no shop, no database, unit suite only. The plugin's own
 *    autoloader already covers `src/` and `tests/`, so there is nothing to do.
 *  - With a Shopware project's PHPUnit, the plugin sitting under `custom/plugins/`
 *    (`vendor/bin/phpunit -c custom/plugins/<dir>/phpunit.xml.dist`): the project's
 *    autoloader is what PHPUnit came with, and the integration suite needs a booted
 *    kernel. Shopware's TestBootstrapper installs `<database>_test` on the first run and
 *    keeps the plugin installed and active in it - the same setup shopware/github-actions
 *    provides in CI.
 *  - The same, but only the unit suite is wanted and booting a shop for it is a waste:
 *    `FASTMON_TEST_MODE=unit` skips the kernel.
 *
 * Which PHPUnit launched us is read off Composer: the loader registered for this
 * plugin's `vendor/` means the first case, anything else means a project.
 */
$pluginVendor = realpath(__DIR__ . '/../vendor');

if ($pluginVendor !== false && isset(ClassLoader::getRegisteredLoaders()[$pluginVendor])) {
    return;
}

$projectRoot = realpath(__DIR__ . '/../../../..');
$projectAutoload = $projectRoot === false ? '' : $projectRoot . '/vendor/autoload.php';

if ($projectAutoload === '' || !is_file($projectAutoload)) {
    fwrite(STDERR, "No autoloader found. Run `composer install` in the plugin directory for the unit suite, or run the tests from a Shopware project for the integration suite.\n");
    exit(1);
}

/** @var ClassLoader $loader */
$loader = require $projectAutoload;

if (($_SERVER['FASTMON_TEST_MODE'] ?? getenv('FASTMON_TEST_MODE')) !== 'unit') {
    $loader = (new TestBootstrapper())
        ->addCallingPlugin()
        ->setForceInstallPlugins(true)
        ->bootstrap()
        ->getClassLoader();
}

// The kernel registers `src/` from the plugin table once it boots. Pinning both
// namespaces here keeps the unit tests independent of that, and of the kernel at all.
$loader->addPsr4('Fastmon\\Collector\\', __DIR__ . '/../src');
$loader->addPsr4('Fastmon\\Collector\\Tests\\', __DIR__);
