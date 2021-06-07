<?php

namespace LaminasTest\ApiTools\AssetManager;

use Composer\Composer;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\Installer\InstallationManager;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Laminas\ApiTools\AssetManager\AssetUninstaller;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;

use function file_get_contents;
use function file_put_contents;
use function preg_match;
use function sprintf;
use function var_export;
use function version_compare;

class AssetUninstallerTest extends TestCase
{
    /** @var array */
    protected $installedAssets = [
        'public/api-tools/css/styles.css',
        'public/api-tools/img/favicon.ico',
        'public/api-tools/js/scripts.js',
        'public/api-tools-barbaz/css/styles.css',
        'public/api-tools-barbaz/img/favicon.ico',
        'public/api-tools-barbaz/js/scripts.js',
        'public/api-tools-foobar/images/favicon.ico',
        'public/api-tools-foobar/scripts/scripts.js',
        'public/api-tools-foobar/styles/styles.css',
    ];

    /** @var array  */
    protected $structure = [
        'public' => [
            'api-tools'        => [
                'css' => [
                    'styles.css' => '',
                ],
                'img' => [
                    'favicon.ico' => '',
                ],
                'js'  => [
                    'scripts.js' => '',
                ],
            ],
            'api-tools-barbaz' => [
                'css' => [
                    'styles.css' => '',
                ],
                'img' => [
                    'favicon.ico' => '',
                ],
                'js'  => [
                    'scripts.js' => '',
                ],
            ],
            'api-tools-foobar' => [
                'images'  => [
                    'favicon.ico' => '',
                ],
                'scripts' => [
                    'scripts.js' => '',
                ],
                'styles'  => [
                    'styles.css' => '',
                ],
            ],
        ],
    ];

    /** @var vfsStreamDirectory */
    private $filesystem;

    /** @var PackageInterface|ObjectProphecy */
    private $package;

    /** @var IOInterface|ObjectProphecy */
    private $io;

    public function setUp()
    {
        // Create virtual filesystem
        $this->filesystem = vfsStream::setup('project');
    }

    public function createAssets(): void
    {
        vfsStream::create($this->structure);
    }

    public function createUninstaller(): AssetUninstaller
    {
        vfsStream::newFile('public/.gitignore')->at($this->filesystem);

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

        return new AssetUninstaller(
            $composer->reveal(),
            $this->io->reveal()
        );
    }

    public function getValidConfig(): array
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

    public function testUninstallerAbortsIfNoPublicSubdirIsPresentInProjectRoot()
    {
        $composer = $this->prophesize(Composer::class);
        $composer->getInstallationManager()->shouldNotBeCalled();

        $uninstaller = new AssetUninstaller(
            $composer->reveal(),
            $this->prophesize(IOInterface::class)->reveal()
        );
        $uninstaller->setProjectPath(vfsStream::url('project'));

        $package = $this->prophesize(PackageInterface::class);
        $this->assertNull($uninstaller($package->reveal()));
    }

    public function testUninstallerAbortsIfNoPublicGitignoreFileFound()
    {
        vfsStream::newDirectory('public')->at($this->filesystem);

        $composer = $this->prophesize(Composer::class);
        $composer->getInstallationManager()->shouldNotBeCalled();

        $uninstaller = new AssetUninstaller(
            $composer->reveal(),
            $this->prophesize(IOInterface::class)->reveal()
        );
        $uninstaller->setProjectPath(vfsStream::url('project'));

        $package = $this->prophesize(PackageInterface::class);

        $this->assertNull($uninstaller($package->reveal()));
    }

    public function testUninstallerAbortsIfPackageDoesNotHaveConfiguration()
    {
        vfsStream::newDirectory('public')->at($this->filesystem);
        $this->createAssets();

        $uninstaller = $this->createUninstaller();
        $uninstaller->setProjectPath(vfsStream::url('project'));

        $this->assertNull($uninstaller($this->package->reveal()));

        foreach ($this->installedAssets as $asset) {
            $path = vfsStream::url('project/', $asset);
            $this->assertFileExists($path, sprintf('Expected file "%s"; file not found!', $path));
        }
    }

    public function testUninstallerAbortsIfConfigurationDoesNotContainAssetInformation()
    {
        vfsStream::newDirectory('public')->at($this->filesystem);
        $this->createAssets();

        vfsStream::newFile('vendor/org/package/config/module.config.php')
            ->at($this->filesystem)
            ->setContent('<' . "?php\nreturn [];");

        $uninstaller = $this->createUninstaller();
        $uninstaller->setProjectPath(vfsStream::url('project'));

        $this->assertNull($uninstaller($this->package->reveal()));

        foreach ($this->installedAssets as $asset) {
            $path = vfsStream::url('project/', $asset);
            $this->assertFileExists($path, sprintf('Expected file "%s"; file not found!', $path));
        }
    }

    public function testUninstallerAbortsIfConfiguredAssetsAreNotPresentInDocroot()
    {
        vfsStream::newDirectory('public')->at($this->filesystem);

        vfsStream::newFile('vendor/org/package/config/module.config.php')
            ->at($this->filesystem)
            ->setContent(sprintf('<' . "?php\nreturn %s;", var_export($this->getValidConfig(), true)));

        $uninstaller = $this->createUninstaller();
        $uninstaller->setProjectPath(vfsStream::url('project'));

        // Seeding the .gitignore happens after createUninstaller, as that
        // seeds an empty file by default.
        $gitignore = "\napi-tools/\napi-tools-barbaz/\napi-tools-foobar/";
        file_put_contents(
            vfsStream::url('project/public/.gitignore'),
            $gitignore
        );

        $this->assertNull($uninstaller($this->package->reveal()));

        $test = file_get_contents(vfsStream::url('project/public/.gitignore'));
        $this->assertEquals($gitignore, $test);
    }

    public function testUninstallerRemovesAssetsFromDocumentRootBasedOnConfiguration()
    {
        vfsStream::newDirectory('public')->at($this->filesystem);
        $this->createAssets();

        vfsStream::newFile('vendor/org/package/config/module.config.php')
            ->at($this->filesystem)
            ->setContent(sprintf('<' . "?php\nreturn %s;", var_export($this->getValidConfig(), true)));

        $uninstaller = $this->createUninstaller();
        $uninstaller->setProjectPath(vfsStream::url('project'));

        // Seeding the .gitignore happens after createUninstaller, as that
        // seeds an empty file by default.
        $gitignore = "\napi-tools/\napi-tools-barbaz/\napi-tools-foobar/";
        file_put_contents(
            vfsStream::url('project/public/.gitignore'),
            $gitignore
        );

        $this->assertNull($uninstaller($this->package->reveal()));

        foreach ($this->installedAssets as $asset) {
            $path = sprintf('%s/%s', vfsStream::url('project'), $asset);
            $this->assertFileNotExists($path, sprintf('File "%s" exists when it should have been removed', $path));
        }

        $test = file_get_contents(vfsStream::url('project/public/.gitignore'));
        $this->assertRegexp('/^\s*$/s', $test);
    }

    public function testUninstallerDoesNotRemoveAssetsFromDocumentRootIfGitignoreEntryIsMissing()
    {
        vfsStream::newDirectory('public')->at($this->filesystem);
        $this->createAssets();

        vfsStream::newFile('vendor/org/package/config/module.config.php')
            ->at($this->filesystem)
            ->setContent(sprintf('<' . "?php\nreturn %s;", var_export($this->getValidConfig(), true)));

        $uninstaller = $this->createUninstaller();
        $uninstaller->setProjectPath(vfsStream::url('project'));

        // Seeding the .gitignore happens after createUninstaller, as that
        // seeds an empty file by default.
        $gitignore = "\napi-tools-barbaz/\napi-tools-foobar/";
        file_put_contents(
            vfsStream::url('project/public/.gitignore'),
            $gitignore
        );

        $this->assertNull($uninstaller($this->package->reveal()));

        foreach ($this->installedAssets as $asset) {
            $path = sprintf('%s/%s', vfsStream::url('project'), $asset);

            switch (true) {
                case preg_match('#/api-tools/#', $asset):
                    $this->assertFileExists($path, sprintf('Expected file "%s"; not found', $path));
                    break;
                case preg_match('#/api-tools-barbaz/#', $asset):
                    // fall-through
                case preg_match('#/api-tools-foobar/#', $asset):
                    // fall-through
                default:
                    $this->assertFileNotExists(
                        $path,
                        sprintf('File "%s" exists when it should have been removed', $path)
                    );
                    break;
            }
        }

        $test = file_get_contents(vfsStream::url('project/public/.gitignore'));
        $this->assertEmpty($test);
    }

    /** @psalm-return array<string, array{0: string}> */
    public function problematicConfiguration(): array
    {
        return [
            'eval' => [__DIR__ . '/TestAsset/problematic-configs/eval.config.php'],
            'exit' => [__DIR__ . '/TestAsset/problematic-configs/exit.config.php'],
        ];
    }

    /**
     * @dataProvider problematicConfiguration
     */
    public function testUninstallerSkipsConfigFilesUsingProblematicConstructs(string $configFile)
    {
        vfsStream::newDirectory('public')->at($this->filesystem);

        vfsStream::newFile('vendor/org/package/config/module.config.php')
            ->at($this->filesystem)
            ->setContent(file_get_contents($configFile));

        $uninstaller = $this->createUninstaller();
        $uninstaller->setProjectPath(vfsStream::url('project'));

        $this->io
            ->writeError(
                Argument::containingString('Unable to check for asset configuration in')
            )
            ->shouldBeCalled();

        $this->assertNull($uninstaller($this->package->reveal()));

        foreach ($this->installedAssets as $asset) {
            $path = vfsStream::url('project/', $asset);
            $this->assertFileExists($path, sprintf('Expected file "%s"; file not found!', $path));
        }
    }

    /** @psalm-return array<string, array{0: string}> */
    public function configFilesWithoutAssetManagerConfiguration(): array
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
     */
    public function testUninstallerSkipsConfigFilesThatDoNotContainAssetManagerString(string $configFile)
    {
        vfsStream::newDirectory('public')->at($this->filesystem);

        vfsStream::newFile('vendor/org/package/config/module.config.php')
            ->at($this->filesystem)
            ->setContent(file_get_contents($configFile));

        $uninstaller = $this->createUninstaller();
        $uninstaller->setProjectPath(vfsStream::url('project'));

        $this->assertNull($uninstaller($this->package->reveal()));

        foreach ($this->installedAssets as $asset) {
            $path = vfsStream::url('project/', $asset);
            $this->assertFileExists($path, sprintf('Expected file "%s"; file not found!', $path));
        }
    }

    /**
     * @todo Remove for version 2.0, when support for Composer 1.0 is removed.
     */
    public function testUninstallerCanHandlePackageEventDuringMigration()
    {
        if (version_compare(PluginInterface::PLUGIN_API_VERSION, '2.0', 'gte')) {
            $this->markTestSkipped(
                'No need to test on composer 2.0 as this is only related to migration 1.2 => 1.3'
            );
        }

        vfsStream::newDirectory('public')->at($this->filesystem);
        $this->createAssets();

        $uninstaller = $this->createUninstaller();
        $uninstaller->setProjectPath(vfsStream::url('project'));

        $operation = $this->prophesize(UninstallOperation::class);
        $operation
            ->getPackage()
            ->willReturn($this->package->reveal())
            ->shouldBeCalled();

        $packageEvent = $this->createPackageEvent($operation->reveal());

        $this->assertNull($uninstaller($packageEvent->reveal()));
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
