<?php

declare(strict_types=1);

namespace Laminas\ApiTools\AssetManager;

use Composer\Composer;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use DirectoryIterator;

use function array_diff;
use function array_search;
use function assert;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function getcwd;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function preg_split;
use function rmdir;
use function scandir;
use function sprintf;
use function unlink;

class AssetUninstaller
{
    use UnparseableTokensTrait;

    /** @var Composer */
    private $composer;

    /** @var array .gitignore rules */
    private $gitignore;

    /** @var IOInterface */
    private $io;

    /** @var string Base path for project; default is current working dir. */
    private $projectPath;

    public function __construct(Composer $composer, IOInterface $io)
    {
        $this->composer    = $composer;
        $this->io          = $io;
        $this->projectPath = getcwd();
    }

    /**
     * Allow overriding the project path (primarily for testing).
     *
     * @param string $path
     * @return void
     */
    public function setProjectPath($path)
    {
        $this->projectPath = $path;
    }

    /**
     * @todo Add explicit typehint for version 2.0.
     * @param PackageInterface $package
     */
    public function __invoke($package) /*PackageInterface*/
    {
        $publicPath = sprintf('%s/public', $this->projectPath);
        if (! is_dir($publicPath)) {
            // No public path in the project; nothing to remove
            return;
        }

        $gitignoreFile = sprintf('%s/.gitignore', $publicPath);
        if (! file_exists($gitignoreFile)) {
            // No .gitignore rules; nothing to remove
            return;
        }

        $package = $this->package($package);

        $installer   = $this->composer->getInstallationManager();
        $packagePath = $installer->getInstallPath($package);

        $packageConfigPath = sprintf('%s/config/module.config.php', $packagePath);
        if (! file_exists($packageConfigPath)) {
            // No module configuration defined; nothing to remove
            return;
        }

        if (! $this->configFileNeedsParsing($packageConfigPath)) {
            return;
        }

        if (! $this->isParseableContent($packageConfigPath)) {
            $this->io->writeError(sprintf(
                'Unable to check for asset configuration in %s; '
                . 'file uses one or more exit() or eval() statements.',
                $packageConfigPath
            ));
            return;
        }

        $packageConfig = include $packageConfigPath;
        if (
            ! is_array($packageConfig)
            || ! isset($packageConfig['asset_manager']['resolver_configs']['paths'])
            || ! is_array($packageConfig['asset_manager']['resolver_configs']['paths'])
        ) {
            // No assets defined; nothing to remove
            return;
        }

        $this->gitignore = $this->fetchIgnoreRules($gitignoreFile);

        $paths = $packageConfig['asset_manager']['resolver_configs']['paths'];

        foreach ($paths as $path) {
            $this->removeAssets($path, $publicPath);
        }

        file_put_contents($gitignoreFile, implode("\n", $this->gitignore));
    }

    /**
     * Discover and remove assets from the public path.
     *
     * @param string $path Path containing asset directories
     * @param string $publicPath Public directory/document root of project
     */
    private function removeAssets($path, $publicPath): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (new DirectoryIterator($path) as $file) {
            if (! $file->isDir()) {
                // Not a directory; continue
                continue;
            }

            $assetPath = $file->getBaseName();
            if (in_array($assetPath, ['.', '..'])) {
                // Dot directory; continue
                continue;
            }

            $gitignoreEntry = sprintf('%s/', $assetPath);
            if (! in_array($gitignoreEntry, $this->gitignore)) {
                // Not in the public gitignore rules; continue
                continue;
            }

            $pathToRemove = sprintf('%s/%s', $publicPath, $assetPath);
            if (! is_dir($pathToRemove)) {
                // Asset directory does not exist; continue
                continue;
            }

            $this->remove($pathToRemove);
            unset($this->gitignore[array_search($gitignoreEntry, $this->gitignore, true)]);
        }
    }

    /**
     * Recursively remove a tree
     *
     * @param string $tree Filesystem tree to recursively delete
     */
    private function remove($tree): bool
    {
        $files = array_diff(scandir($tree), ['.', '..']);

        foreach ($files as $file) {
            $path = sprintf('%s/%s', $tree, $file);
            if (is_dir($path)) {
                $this->remove($path);
                continue;
            }

            unlink($path);
        }

        return rmdir($tree);
    }

    /**
     * Retrieve and parse gitignore rules.
     *
     * @param string $file Filename of .gitignore file
     * @return array Array of lines from the file
     */
    private function fetchIgnoreRules($file)
    {
        $text = file_get_contents($file);
        return preg_split("/(\r\n|\r|\n)/", $text);
    }

    /**
     * @deprecated Can be removed with next major. Migration guide should
     *     suggest upgrading to latest minor before upgrading major.
     *
     * @param PackageInterface $package
     * @return PackageInterface
     */
    private function package($package)
    {
        if ($package instanceof PackageInterface) {
            return $package;
        }

        assert($package instanceof PackageEvent);
        $operation = $package->getOperation();
        assert($operation instanceof UninstallOperation);

        return $operation->getPackage();
    }
}
