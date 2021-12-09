<?php

declare(strict_types=1);

namespace Laminas\ApiTools\AssetManager;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use DirectoryIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function array_search;
use function assert;
use function copy;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function getcwd;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function mkdir;
use function preg_split;
use function sprintf;
use function strlen;
use function substr;

class AssetInstaller
{
    use UnparseableTokensTrait;

    /** @var Composer */
    private $composer;

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
            return;
        }

        $package = $this->package($package);

        $installer   = $this->composer->getInstallationManager();
        $packagePath = $installer->getInstallPath($package);

        $packageConfigPath = sprintf('%s/config/module.config.php', $packagePath);
        if (! file_exists($packageConfigPath)) {
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
            return;
        }

        $paths = $packageConfig['asset_manager']['resolver_configs']['paths'];

        foreach ($paths as $path) {
            $this->copyAssets($path, $publicPath);
        }
    }

    /**
     * Descend into asset directories and recursively copy to the project path.
     *
     * @param string $path Path containing asset directories
     * @param string $publicPath Public directory/document root of project
     */
    private function copyAssets($path, $publicPath): void
    {
        if (! is_dir($path)) {
            return;
        }

        $gitignoreFile = sprintf('%s/.gitignore', $publicPath);

        foreach (new DirectoryIterator($path) as $file) {
            if (! $file->isDir()) {
                continue;
            }

            $assetPath = $file->getBaseName();
            if (in_array($assetPath, ['.', '..'])) {
                continue;
            }

            $this->copy($file->getRealPath(), $publicPath);
            $this->updateGitignore($gitignoreFile, $assetPath);
        }
    }

    /**
     * Recursively copyfiles from the source to the destination.
     *
     * @param string $source Path containing source files.
     * @param string $destination Path to which to copy files.
     */
    private function copy($source, $destination): void
    {
        $trimLength = strlen(dirname($source)) + 1;
        $rdi        = new RecursiveDirectoryIterator($source);
        $rii        = new RecursiveIteratorIterator($rdi, RecursiveIteratorIterator::LEAVES_ONLY);

        foreach ($rii as $file) {
            if ($file->isDir()) {
                continue;
            }

            $sourceFile      = $file->getRealPath();
            $destinationFile = sprintf('%s/%s', $destination, substr($sourceFile, $trimLength));
            $destinationPath = dirname($destinationFile);
            if (! is_dir($destinationPath)) {
                mkdir($destinationPath, 0775, true);
            }
            copy($sourceFile, $destinationFile);
        }
    }

    /**
     * Append a path to the public directory's .gitignore
     *
     * @param string $gitignoreFile
     * @param string $path
     */
    private function updateGitignore($gitignoreFile, $path): void
    {
        $gitignoreContents = file_exists($gitignoreFile)
            ? file_get_contents($gitignoreFile)
            : '';

        if (false === $gitignoreContents) {
            return;
        }

        $path  = sprintf("%s/", $path);
        $lines = preg_split("/(\r\n?|\n)/", $gitignoreContents);
        if (false !== array_search($path, $lines)) {
            return;
        }

        $lines[] = $path;

        file_put_contents($gitignoreFile, implode("\n", $lines));
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

        if ($operation instanceof UpdateOperation) {
            return $operation->getTargetPackage();
        }

        assert($operation instanceof InstallOperation);
        return $operation->getPackage();
    }
}
