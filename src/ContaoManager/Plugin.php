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

namespace Pdir\ContaoWorkmateAgentBundle\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Contao\ManagerPlugin\Routing\RoutingPluginInterface;
use Pdir\ContaoWorkmateAgentBundle\PdirContaoWorkmateAgentBundle;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouteCollection;

class Plugin implements BundlePluginInterface, RoutingPluginInterface
{
    public function getBundles(ParserInterface $parser): array
    {
        return [
            BundleConfig::create(PdirContaoWorkmateAgentBundle::class)->setLoadAfter([ContaoCoreBundle::class]),
        ];
    }

    public function getRouteCollection(LoaderResolverInterface $resolver, KernelInterface $kernel): ?RouteCollection
    {
        $file = __DIR__.'/../../config/routes.yaml';
        $loader = $resolver->resolve($file);

        return false === $loader ? null : $loader->load($file);
    }
}
