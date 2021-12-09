<?php

declare(strict_types=1);

namespace Laminas\ApiTools\AssetManager;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

use function array_shift;
use function count;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    /** @var Composer */
    private $composer;

    /**
     * Array of installers to run following a dump-autoload operation.
     *
     * @var callable[]
     */
    private $installers = [];

    /** @var IOInterface */
    private $io;

    /**
     * Provide composer event listeners.
     *
     * {@inheritDoc}
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
     * @return void
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io       = $io;
    }

    /**
     * Execute all installers.
     *
     * @return void
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
     * @return void
     */
    public function onPostPackageInstall(PackageEvent $event)
    {
        $operation = $event->getOperation();
        if (! $operation instanceof InstallOperation) {
            return;
        }

        $package            = $operation->getPackage();
        $this->installers[] = function () use ($package): void {
            $installer = new AssetInstaller($this->composer, $this->io);
            $installer($package);
        };
    }

    /**
     * Installs assets for a package being updated.
     *
     * Memoizes an install operation to run post-autoload-dump.
     *
     * @return void
     */
    public function onPostPackageUpdate(PackageEvent $event)
    {
        $operation = $event->getOperation();
        if (! $operation instanceof UpdateOperation) {
            return;
        }

        $targetPackage = $operation->getTargetPackage();

        // Install new assets; delay until post-autoload-dump
        $this->installers[] = function () use ($targetPackage): void {
            $installer = new AssetInstaller($this->composer, $this->io);
            $installer($targetPackage);
        };
    }

    /**
     * Uninstall assets provided by the package, if any.
     *
     * @return void
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
     * @return void
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

    /**
     * @return void
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    /**
     * @return void
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
    }
}
