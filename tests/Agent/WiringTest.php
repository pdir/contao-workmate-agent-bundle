<?php

declare(strict_types=1);

/*
 * pdir Workmate agent for Contao Open Source CMS
 *
 * Copyright (C) 2026 pdir / digital agentur // pdir GmbH
 *
 * @package    contao-workmate-agent-bundle
 * @license    LGPL-3.0-or-later
 * @author     pdir GmbH <develop@pdir.de>
 */
namespace Pdir\ContaoWorkmateAgentBundle\Tests\Agent;

use Contao\CoreBundle\Doctrine\Backup\Backup;
use Contao\CoreBundle\Doctrine\Backup\BackupManager;
use Contao\CoreBundle\Doctrine\Backup\Config\CreateConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Pdir\ContaoWorkmateAgentBundle\Agent\BackupExporter;
use Pdir\ContaoWorkmateAgentBundle\ContaoManager\Plugin;
use Pdir\ContaoWorkmateAgentBundle\Controller\AgentController;
use Pdir\ContaoWorkmateAgentBundle\DependencyInjection\PdirContaoWorkmateAgentExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Loader\YamlFileLoader;

class WiringTest extends TestCase
{
    public function testContainerCompiles(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->setDefinition('contao.doctrine.backup_manager', new Definition(\stdClass::class));

        (new PdirContaoWorkmateAgentExtension())->load([], $container);
        $container->compile();

        $this->assertInstanceOf(AgentController::class, $container->get(AgentController::class));
    }

    public function testRoute(): void
    {
        $resolver = new LoaderResolver([new YamlFileLoader(new FileLocator())]);
        $routes = (new Plugin())->getRouteCollection($resolver, $this->createMock(KernelInterface::class));
        $route = $routes->get('workmate_agent');

        $this->assertSame('/_workmate/agent', $route->getPath());
        $this->assertSame(['POST'], $route->getMethods());
        $this->assertCount(1, (new Plugin())->getBundles($this->createMock(ParserInterface::class)));
    }

    public function testExportWithContaoBackupManager(): void
    {
        $config = new CreateConfig(new Backup('backup__20261108120000.sql.gz'));
        $manager = $this->createMock(BackupManager::class);
        $manager->method('createCreateConfig')->willReturn($config);
        $manager->expects($this->once())->method('create')->with($this->callback(static function (CreateConfig $created): bool {
            return !$created->isGzCompressionEnabled();
        }));
        $manager->method('readStream')->willReturnCallback(static function () {
            $in = fopen('php://memory', 'w+b');
            fwrite($in, "-- dump\n");
            rewind($in);

            return $in;
        });

        $target = sys_get_temp_dir().'/workmate-agent-'.bin2hex(random_bytes(4)).'/latest.sql';
        $name = (new BackupExporter($manager))->export($target);

        $this->assertSame('backup__20261108120000.sql', $name);
        $this->assertSame("-- dump\n", file_get_contents($target));
        unlink($target);
        rmdir(\dirname($target));
    }
}
