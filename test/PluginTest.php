<?php

namespace LaminasTest\ApiTools\AssetManager;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Laminas\ApiTools\AssetManager\Plugin;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\TestCase;
use Prophecy\Prophecy\ObjectProphecy;
use ReflectionProperty;

class PluginTest extends TestCase
{
    /** @var vfsStreamDirectory */
    private $filesystem;

    /** @var Composer|ObjectProphecy */
    private $composer;

    /** @var IOInterface|ObjectProphecy */
    private $io;

    public function setUp()
    {
        // Create virtual filesystem
        $this->filesystem = vfsStream::setup('project');

        $this->composer = $this->prophesize(Composer::class);
        $this->io       = $this->prophesize(IOInterface::class);
    }

    public function testSubscribesToExpectedEvents()
    {
        $this->assertEquals([
            'post-autoload-dump'    => 'onPostAutoloadDump',
            'post-package-install'  => 'onPostPackageInstall',
            'post-package-update'   => 'onPostPackageUpdate',
            'pre-package-uninstall' => 'onPrePackageUninstall',
            'pre-package-update'    => 'onPrePackageUpdate',
        ], Plugin::getSubscribedEvents());
    }

    public function testPostPackageInstallShouldMemoizeInstallerCallback()
    {
        $plugin = new Plugin();
        $this->assertNull($plugin->activate($this->composer->reveal(), $this->io->reveal()));

        $installEvent = $this->prophesize(PackageEvent::class);
        $operation    = $this->prophesize(InstallOperation::class);
        $package      = $this->prophesize(PackageInterface::class);
        $operation->getPackage()->will([$package, 'reveal'])->shouldBeCalled();

        $installEvent->getOperation()->will([$operation, 'reveal'])->shouldBeCalled();
        $this->assertNull($plugin->onPostPackageInstall($installEvent->reveal()));
        $this->assertAttributeCount(1, 'installers', $plugin);
    }

    public function testPrePackageUninstallShouldTriggerAssetUninstaller()
    {
        $plugin = new Plugin();
        $this->assertNull($plugin->activate($this->composer->reveal(), $this->io->reveal()));

        $uninstallEvent = $this->mockUninstallEvent();
        $this->assertNull($plugin->onPrePackageUninstall($uninstallEvent));
    }

    public function testPreUpdateOperationShouldTriggerAssetUninstaller()
    {
        $plugin = new Plugin();
        $this->assertNull($plugin->activate($this->composer->reveal(), $this->io->reveal()));

        $updateEvent = $this->mockPreUpdateEvent();
        $this->assertNull($plugin->onPrePackageUpdate($updateEvent));
        $this->assertAttributeCount(0, 'installers', $plugin);
    }

    public function testPostUpdateOperationShouldMemoizeInstallOperation()
    {
        $plugin = new Plugin();
        $this->assertNull($plugin->activate($this->composer->reveal(), $this->io->reveal()));

        $updateEvent = $this->mockPostUpdateEvent();
        $this->assertNull($plugin->onPostPackageUpdate($updateEvent));
        $this->assertAttributeCount(1, 'installers', $plugin);
    }

    public function testOnPostAutoloadDumpTriggersInstallers()
    {
        $spy = (object) ['operations' => []];

        $installer1 = function () use ($spy) {
            $spy->operations[] = 'installer1';
        };
        $installer2 = function () use ($spy) {
            $spy->operations[] = 'installer2';
        };

        $plugin = new Plugin();

        $r = new ReflectionProperty($plugin, 'installers');
        $r->setAccessible(true);
        $r->setValue($plugin, [$installer1, $installer2]);

        $expected = [
            'installer1',
            'installer2',
        ];

        $this->assertNull($plugin->onPostAutoloadDump());

        $this->assertSame($expected, $spy->operations);
    }

    private function mockUninstallEvent(): PackageEvent
    {
        vfsStream::newFile('public/.gitignore')->at($this->filesystem);

        $event     = $this->prophesize(PackageEvent::class);
        $operation = $this->prophesize(UninstallOperation::class);
        $event->getOperation()->willReturn($operation)->shouldBeCalled();
        $package = $this->prophesize(PackageInterface::class);
        $operation->getPackage()->willReturn($package)->shouldBeCalled();

        return $event->reveal();
    }

    private function mockPostUpdateEvent(): PackageEvent
    {
        $targetPackage = $this->prophesize(PackageInterface::class);

        $operation = $this->prophesize(UpdateOperation::class);
        $operation->getInitialPackage()->shouldNotBeCalled();
        $operation->getTargetPackage()->will([$targetPackage, 'reveal']);

        $event = $this->prophesize(PackageEvent::class);
        $event
            ->getOperation()
            ->will([$operation, 'reveal'])
            ->shouldBeCalled();

        return $event->reveal();
    }

    private function mockPreUpdateEvent(): PackageEvent
    {
        $initialPackage = $this->prophesize(PackageInterface::class);

        $operation = $this->prophesize(UpdateOperation::class);
        $operation->getInitialPackage()->will([$initialPackage, 'reveal']);
        $operation->getTargetPackage()->shouldNotBeCalled();

        $event = $this->prophesize(PackageEvent::class);
        $event
            ->getOperation()
            ->will([$operation, 'reveal'])
            ->shouldBeCalled();

        return $event->reveal();
    }
}
