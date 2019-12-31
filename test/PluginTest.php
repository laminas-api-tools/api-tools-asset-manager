<?php

/**
 * @see       https://github.com/laminas-api-tools/api-tools-asset-manager for the canonical source repository
 * @copyright https://github.com/laminas-api-tools/api-tools-asset-manager/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas-api-tools/api-tools-asset-manager/blob/master/LICENSE.md New BSD License
 */

namespace LaminasTest\ApiTools\AssetManager;

use Laminas\ApiTools\AssetManager\Plugin;
use PHPUnit_Framework_TestCase as TestCase;

class PluginTest extends TestCase
{
    public function testSubscribesToExpectedEvents()
    {
        $this->assertEquals([
            'post-package-install' => 'onPostPackageInstall',
            'pre-package-uninstall' => 'onPrePackageUninstall',
        ], Plugin::getSubscribedEvents());
    }
}
