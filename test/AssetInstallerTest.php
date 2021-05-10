<?php

namespace LaminasTest\ApiTools\AssetManager;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\Installer\InstallationManager;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Laminas\ApiTools\AssetManager\AssetInstaller;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use function version_compare;

class AssetInstallerTest extends TestCase
{
    protected $expectedAssets = [
        'api-tools/css/styles.css',
        'api-tools/img/favicon.ico',
        'api-tools/js/scripts.js',
        'api-tools-barbaz/css/styles.css',
        'api-tools-barbaz/img/favicon.ico',
        'api-tools-barbaz/js/scripts.js',
        'api-tools-foobar/images/favicon.ico',
        'api-tools-foobar/scripts/scripts.js',
        'api-tools-foobar/styles/styles.css',
    ];

    /**
     * @var PackageInterface|\Prophecy\Prophecy\ObjectProphecy
     */
    private $package;

    /**
     * @var IOInterface|\Prophecy\Prophecy\ObjectProphecy
     */
    private $io;

    /**
     * @var \org\bovigo\vfs\vfsStreamDirectory
     */
    private $filesystem;

    public function setUp()
    {
        // Create virtual filesystem
        $this->filesystem = vfsStream::setup('project');
    }

    public function createInstaller()
    {
        $this->package = $this->prophesize(PackageInterface::class);

        $installationManager = $this->prophesize(InstallationManager::class);
        $installationManager
            ->getInstallPath($this->package->reveal())
            ->willReturn(vfsStream::url('project/vendor/org/package'))
            ->shouldBeCalled();

        $composer = $this->prophesize(Composer::class);
        $composer
            ->getInstallationManager()
            ->will([$installationManager, 'reveal'])
            ->shouldBeCalled();

        $this->io = $this->prophesize(IOInterface::class);

        return new AssetInstaller(
            $composer->reveal(),
            $this->io->reveal()
        );
    }

    public function getValidConfig()
    {
        return [
            'asset_manager' => [
                'resolver_configs' => [
                    'paths' => [
                        __DIR__ . '/TestAsset/asset-set-1',
                        __DIR__ . '/TestAsset/asset-set-2',
                    ],
                ],
            ],
        ];
    }

    public function testInstallerAbortsIfNoPublicSubdirIsPresentInProjectRoot()
    {
        $composer = $this->prophesize(Composer::class);
        $composer->getInstallationManager()->shouldNotBeCalled();

        $installer = new AssetInstaller(
            $composer->reveal(),
            $this->prophesize(IOInterface::class)->reveal()
        );
        $installer->setProjectPath(vfsStream::url('project'));

        $package = $this->prophesize(PackageInterface::class);

        $this->assertNull($installer($package->reveal()));
    }

    public function testInstallerAbortsIfPackageDoesNotHaveConfiguration()
    {
        vfsStream::newDirectory('public')->at($this->filesystem);

        $installer = $this->createInstaller();
        $installer->setProjectPath(vfsStream::url('project'));

        $this->assertNull($installer($this->package->reveal()));

        foreach ($this->expectedAssets as $asset) {
            $path = vfsStream::url('project/public/' . $asset);
            $this->assertFileNotExists($path, sprintf('File %s discovered, when it should not exist', $path));
        }
    }

    public function testInstallerAbortsIfConfigurationDoesNotContainAssetInformation()
    {
        vfsStream::newDirectory('public')->at($this->filesystem);

        vfsStream::newFile('vendor/org/package/config/module.config.php')
            ->at($this->filesystem)
            ->setContent('<' . "?php\nreturn [];");

        $installer = $this->createInstaller();
        $installer->setProjectPath(vfsStream::url('project'));

        $this->assertNull($installer($this->package->reveal()));

        foreach ($this->expectedAssets as $asset) {
            $path = vfsStream::url('project/public/' . $asset);
            $this->assertFileNotExists($path, sprintf('File %s discovered, when it should not exist', $path));
        }
    }

    public function testInstallerCopiesAssetsToDocumentRootBasedOnConfiguration()
    {
        vfsStream::newDirectory('public')->at($this->filesystem);

        vfsStream::newFile('vendor/org/package/config/module.config.php')
            ->at($this->filesystem)
            ->setContent(sprintf('<' . "?php\nreturn %s;", var_export($this->getValidConfig(), true)));

        $installer = $this->createInstaller();
        $installer->setProjectPath(vfsStream::url('project'));

        $this->assertNull($installer($this->package->reveal()));

        foreach ($this->expectedAssets as $asset) {
            $path = vfsStream::url('project/public/' . $asset);
            $this->assertFileExists($path, sprintf('File %s not present, when it should exist', $path));
        }
    }

    public function testInstallerUpdatesPublicGitIgnoreFileWithEntryForEachAssetDirectoryItCopies()
    {
        vfsStream::newDirectory('public')->at($this->filesystem);

        vfsStream::newFile('vendor/org/package/config/module.config.php')
            ->at($this->filesystem)
            ->setContent(sprintf('<' . "?php\nreturn %s;", var_export($this->getValidConfig(), true)));

        $installer = $this->createInstaller();
        $installer->setProjectPath(vfsStream::url('project'));

        $this->assertNull($installer($this->package->reveal()));

        $gitIgnoreFile = vfsStream::url('project/public/.gitignore');
        $this->assertFileExists($gitIgnoreFile, 'public/.gitignore was not created');
        $contents = file_get_contents($gitIgnoreFile);
        $this->assertContains("\napi-tools", $contents, 'public/.gitignore is missing the api-tools/ entry');
        $this->assertContains(
            "\napi-tools-barbaz/",
            $contents,
            'public/.gitignore is missing the api-tools-barbaz/ entry'
        );
        $this->assertContains(
            "\napi-tools-foobar/",
            $contents,
            'public/.gitignore is missing the api-tools-foobar/ entry'
        );
    }

    public function testInstallerDoesNotAddDuplicateEntriesToGitignore()
    {
        vfsStream::newDirectory('public')->at($this->filesystem);

        vfsStream::newFile('public/.gitignore')
            ->at($this->filesystem)
            ->setContent("api-tools/\napi-tools-bar-baz/\napi-tools-foobar/\n");
        $gitIgnoreFile = vfsStream::url('project/public/.gitignore');
        $this->assertFileExists($gitIgnoreFile, 'public/.gitignore was not created; cannot continue test');

        vfsStream::newFile('vendor/org/package/config/module.config.php')
            ->at($this->filesystem)
            ->setContent(sprintf('<' . "?php\nreturn %s;", var_export($this->getValidConfig(), true)));

        $installer = $this->createInstaller();
        $installer->setProjectPath(vfsStream::url('project'));

        $this->assertNull($installer($this->package->reveal()));

        $gitIgnoreContents = file_get_contents($gitIgnoreFile);
        $gitIgnoreContents = explode("\n", $gitIgnoreContents);
        $this->assertEquals(array_unique($gitIgnoreContents), $gitIgnoreContents);
    }

    public function problematicConfiguration()
    {
        return [
            'eval' => [__DIR__ . '/TestAsset/problematic-configs/eval.config.php'],
            'exit' => [__DIR__ . '/TestAsset/problematic-configs/exit.config.php'],
        ];
    }

    /**
     * @dataProvider problematicConfiguration
     * @param string $configFile
     */
    public function testInstallerSkipsConfigFilesUsingProblematicConstructs($configFile)
    {
        vfsStream::newDirectory('public')->at($this->filesystem);

        vfsStream::newFile('vendor/org/package/config/module.config.php')
            ->at($this->filesystem)
            ->setContent(file_get_contents($configFile));

        $installer = $this->createInstaller();
        $installer->setProjectPath(vfsStream::url('project'));

        $this->io
            ->writeError(
                Argument::containingString('Unable to check for asset configuration in')
            )
            ->shouldBeCalled();

        $this->assertNull($installer($this->package->reveal()));

        foreach ($this->expectedAssets as $asset) {
            $path = vfsStream::url('project/public/' . $asset);
            $this->assertFileNotExists($path, sprintf('File %s discovered, when it should not exist', $path));
        }
    }

    public function configFilesWithoutAssetManagerConfiguration()
    {
        return [
            'class'        => [__DIR__ . '/TestAsset/no-asset-manager-configs/class.config.php'],
            'clone'        => [__DIR__ . '/TestAsset/no-asset-manager-configs/clone.config.php'],
            'double-colon' => [__DIR__ . '/TestAsset/no-asset-manager-configs/double-colon.config.php'],
            'eval'         => [__DIR__ . '/TestAsset/no-asset-manager-configs/eval.config.php'],
            'exit'         => [__DIR__ . '/TestAsset/no-asset-manager-configs/exit.config.php'],
            'extends'      => [__DIR__ . '/TestAsset/no-asset-manager-configs/extends.config.php'],
            'interface'    => [__DIR__ . '/TestAsset/no-asset-manager-configs/interface.config.php'],
            'new'          => [__DIR__ . '/TestAsset/no-asset-manager-configs/new.config.php'],
            'trait'        => [__DIR__ . '/TestAsset/no-asset-manager-configs/trait.config.php'],
        ];
    }

    /**
     * @dataProvider configFilesWithoutAssetManagerConfiguration
     * @param string $configFile
     */
    public function testInstallerSkipsConfigFilesThatDoNotContainAssetManagerString($configFile)
    {
        vfsStream::newDirectory('public')->at($this->filesystem);

        vfsStream::newFile('vendor/org/package/config/module.config.php')
            ->at($this->filesystem)
            ->setContent(file_get_contents($configFile));

        $installer = $this->createInstaller();
        $installer->setProjectPath(vfsStream::url('project'));

        $this->io
            ->writeError(Argument::any())
            ->shouldNotBeCalled();

        $this->assertNull($installer($this->package->reveal()));

        foreach ($this->expectedAssets as $asset) {
            $path = vfsStream::url('project/public/' . $asset);
            $this->assertFileNotExists($path, sprintf('File %s discovered, when it should not exist', $path));
        }
    }

    public function testInstallerAllowsConfigurationContainingClassPseudoConstant()
    {
        vfsStream::newDirectory('public')->at($this->filesystem);

        vfsStream::newFile('vendor/org/package/config/module.config.php')
            ->at($this->filesystem)
            ->setContent("<?php\nreturn [\n    'some-key' => AssetInstaller::class,\n    'asset-manager' => []];");

        $installer = $this->createInstaller();
        $installer->setProjectPath(vfsStream::url('project'));

        $this->io
            ->writeError(Argument::any())
            ->shouldNotBeCalled();

        $this->assertNull($installer($this->package->reveal()));

        foreach ($this->expectedAssets as $asset) {
            $path = vfsStream::url('project/public/' . $asset);
            $this->assertFileNotExists($path, sprintf('File %s discovered, when it should not exist', $path));
        }
    }

    /**
     * @todo Remove for version 2.0, when support for Composer 1.0 is removed.
     */
    public function testInstallerCanHandlePackageEventWithInstallOperationDuringMigration()
    {
        if (version_compare(PluginInterface::PLUGIN_API_VERSION, '2.0', 'gte')) {
            $this->markTestSkipped(
                'No need to test on composer 2.0 as this is only related to migration 1.2 => 1.3'
            );
        }

        vfsStream::newDirectory('public')->at($this->filesystem);

        vfsStream::newFile('vendor/org/package/config/module.config.php')
            ->at($this->filesystem)
            ->setContent("<?php\nreturn [\n    'some-key' => AssetInstaller::class,\n    'asset-manager' => []];");

        $installer = $this->createInstaller();
        $installer->setProjectPath(vfsStream::url('project'));

        $this->io
            ->writeError(Argument::any())
            ->shouldNotBeCalled();

        $operation = $this->prophesize(InstallOperation::class);
        $operation
            ->getPackage()
            ->willReturn($this->package->reveal())
            ->shouldBeCalled();

        $packageEvent = $this->createPackageEvent($operation->reveal());

        $this->assertNull($installer($packageEvent->reveal()));
    }

    /**
     * @todo Remove for version 2.0, when support for Composer 1.0 is removed.
     */
    public function testInstallerCanHandlePackageEventWithUpdateOperationDuringMigration()
    {
        if (version_compare(PluginInterface::PLUGIN_API_VERSION, '2.0', 'gte')) {
            $this->markTestSkipped(
                'No need to test on composer 2.0 as this is only related to migration 1.2 => 1.3'
            );
        }

        vfsStream::newDirectory('public')->at($this->filesystem);

        vfsStream::newFile('vendor/org/package/config/module.config.php')
            ->at($this->filesystem)
            ->setContent("<?php\nreturn [\n    'some-key' => AssetInstaller::class,\n    'asset-manager' => []];");

        $installer = $this->createInstaller();
        $installer->setProjectPath(vfsStream::url('project'));

        $this->io
            ->writeError(Argument::any())
            ->shouldNotBeCalled();

        $operation = $this->prophesize(UpdateOperation::class);
        $operation
            ->getTargetPackage()
            ->willReturn($this->package->reveal())
            ->shouldBeCalled();

        $packageEvent = $this->createPackageEvent($operation->reveal());

        $this->assertNull($installer($packageEvent->reveal()));
    }

    /**
     * @todo Remove for version 2.0, when support for Composer 1.0 is removed.
     * @return ObjectProphecy
     */
    private function createPackageEvent(OperationInterface $operation)
    {
        $event = $this->prophesize(PackageEvent::class);
        $event
            ->getOperation()
            ->willReturn($operation)
            ->shouldBeCalled();

        return $event;
    }
}
