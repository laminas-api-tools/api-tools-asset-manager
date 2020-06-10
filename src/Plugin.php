<?php

/**
 * @see       https://github.com/laminas-api-tools/api-tools-asset-manager for the canonical source repository
 * @copyright https://github.com/laminas-api-tools/api-tools-asset-manager/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas-api-tools/api-tools-asset-manager/blob/master/LICENSE.md New BSD License
 */

namespace Laminas\ApiTools\AssetManager;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var Composer
     */
    private $composer;

    /**
     * Array of installers to run following a dump-autoload operation.
     *
     * @var callable[]
     */
    private $installers = [];

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * Provide composer event listeners.
     *
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'post-autoload-dump'    => 'onPostAutoloadDump',
            'post-package-install'  => 'onPostPackageInstall',
            'post-package-update'   => 'onPostPackageUpdate',
            'pre-package-uninstall' => 'onPrePackageUninstall',
            'pre-package-update'    => 'onPrePackageUpdate',
        ];
    }

    /**
     * Activate the plugin
     *
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    /**
     * Execute all installers.
     */
    public function onPostAutoloadDump()
    {
        while (0 < count($this->installers)) {
            $installer = array_shift($this->installers);
            $installer();
        }
    }

    /**
     * Memoize an installer for the package being installed.
     *
     * @param PackageEvent $event
     */
    public function onPostPackageInstall(PackageEvent $event)
    {
        $operation = $event->getOperation();
        if (! $operation instanceof InstallOperation) {
            return;
        }

        $package = $operation->getPackage();
        $this->installers[] = function () use ($package) {
            $installer = new AssetInstaller($this->composer, $this->io);
            $installer($package);
        };
    }

    /**
     * Installs assets for a package being updated.
     *
     * Memoizes an install operation to run post-autoload-dump.
     *
     * @param PackageEvent $event
     */
    public function onPostPackageUpdate(PackageEvent $event)
    {
        $operation = $event->getOperation();
        if (! $operation instanceof UpdateOperation) {
            return;
        }

        $targetPackage = $operation->getTargetPackage();

        // Install new assets; delay until post-autoload-dump
        $this->installers[] = function () use ($targetPackage) {
            $installer = new AssetInstaller($this->composer, $this->io);
            $installer($targetPackage);
        };
    }

    /**
     * Uninstall assets provided by the package, if any.
     *
     * @param PackageEvent $event
     */
    public function onPrePackageUninstall(PackageEvent $event)
    {
        $operation = $event->getOperation();
        if (! $operation instanceof UninstallOperation) {
            return;
        }

        $uninstall = new AssetUninstaller($this->composer, $this->io);
        $uninstall($operation->getPackage());
    }

    /**
     * Removes previously installed assets for a package being updated.
     *
     * @param PackageEvent $event
     */
    public function onPrePackageUpdate(PackageEvent $event)
    {
        $operation = $event->getOperation();
        if (! $operation instanceof UpdateOperation) {
            return;
        }

        $initialPackage = $operation->getInitialPackage();

        // Uninstall any previously installed assets
        $uninstall = new AssetUninstaller($this->composer, $this->io);
        $uninstall($initialPackage);
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }
}
